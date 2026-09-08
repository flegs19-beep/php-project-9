<?php

require __DIR__ . '/../vendor/autoload.php';

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DomCrawler\Crawler;
use Slim\Factory\AppFactory;
use Slim\Flash\Messages;
use Slim\Views\PhpRenderer;
use Valitron\Validator;
use Slim\Exception\HttpNotFoundException;
use DI\Container;
use function DI\factory;

session_start();

$databaseUrl = parse_url(getenv('DATABASE_URL'));

$dsn = sprintf(
    'pgsql:host=%s;port=%s;dbname=%s',
    $databaseUrl['host'],
    $databaseUrl['port'] ?? 5432,
    ltrim($databaseUrl['path'], '/')
);

$container = new Container();

$container->set(PDO::class, factory(function () use ($dsn, $databaseUrl) {
    return new PDO($dsn, rawurldecode($databaseUrl['user']), rawurldecode($databaseUrl['pass']), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}));

$renderer = new PhpRenderer(__DIR__ . '/../templates');
$renderer->setLayout('layout.phtml');

$container->set(PhpRenderer::class, $renderer);
$container->set(Messages::class, new Messages());
$container->set(Client::class, new Client([
    'timeout' => 10,
]));

AppFactory::setContainer($container);
$app = AppFactory::create();

$routeParser = $app->getRouteCollector()->getRouteParser();

$container->get(PhpRenderer::class)->addAttribute('router', $routeParser);
$container->get(PhpRenderer::class)->addAttribute('flash', $container->get(Messages::class));

$errorMiddleware = $app->addErrorMiddleware(false, true, true);
$responseFactory = $app->getResponseFactory();

$errorMiddleware->setDefaultErrorHandler(
    function ($request, Throwable $exception) use ($container, $responseFactory) {
        $renderer = $container->get(PhpRenderer::class);
        $response = $responseFactory->createResponse();

        if ($exception instanceof HttpNotFoundException) {
            return $renderer->render(
                $response->withStatus(404),
                'errors/404.phtml'
            );
        }

        return $renderer->render(
            $response->withStatus(500),
            'errors/500.phtml'
        );
    }
);


$app->get('/', function ($request, $response) use ($container) {
    $renderer = $container->get(PhpRenderer::class);
    return $renderer->render($response, 'index.phtml');
})->setName('home');

$app->post('/urls', function ($request, $response) use ($container, $routeParser) {
    $renderer = $container->get(PhpRenderer::class);
    $pdo = $container->get(PDO::class);
    $flash = $container->get(Messages::class);

    $data = (array) $request->getParsedBody();
    $url = trim($data['url'] ?? '');

    $validator = new Validator(['url' => $url]);
    $validator->rule('required', 'url')->message('URL не должен быть пустым');
    $validator->rule('url', 'url')->message('Некорректный URL');
    $validator->rule('lengthMax', 'url', 255)->message('URL превышает 255 символов');

    if (!$validator->validate()) {
            return $renderer->render(
                $response->withStatus(422),
                'index.phtml',
                ['errors' => $validator->errors(), 'url' => $url]
            );
    }

    $parsedUrl = parse_url($url);
    $scheme = strtolower($parsedUrl['scheme']);
    $host = strtolower($parsedUrl['host']);
    $port = isset($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '';
    $name = "{$scheme}://{$host}{$port}";

    $statement = $pdo->prepare('SELECT id FROM urls WHERE name = :name');
    $statement->execute(['name' => $name]);
    $existingUrl = $statement->fetch();

    if ($existingUrl !== false) {
        $flash->addMessage('info', 'Страница уже существует');
        return $response
            ->withHeader('Location', $routeParser->urlFor('urls.show', ['id' => (string) $existingUrl['id']]))
            ->withStatus(302);
    }

    $statement = $pdo->prepare('INSERT INTO urls (name, created_at) VALUES (:name, :created_at) RETURNING id');
    $statement->execute(['name' => $name, 'created_at' => Carbon::now()->format('Y-m-d H:i:s')]);

    $id = $statement->fetchColumn();
    $flash->addMessage('success', 'Страница успешно добавлена');

    return $response
        ->withHeader('Location', $routeParser->urlFor('urls.show', ['id' => (string) $id]))
        ->withStatus(302);
})->setName('urls.store');

$app->get('/urls', function ($request, $response) use ($container) {
    $renderer = $container->get(PhpRenderer::class);
    $pdo = $container->get(PDO::class);

    $urls = $pdo->query('SELECT id, name, created_at FROM urls ORDER BY created_at DESC, id DESC')->fetchAll();
    $checks = $pdo->query(
        'SELECT DISTINCT ON (url_id) url_id, created_at AS last_check_at, status_code AS last_status_code ' .
        'FROM url_checks ORDER BY url_id, created_at DESC, id DESC'
    )->fetchAll();

    $checksByUrlId = [];
    foreach ($checks as $check) {
        $checksByUrlId[$check['url_id']] = $check;
    }

    foreach ($urls as $index => $url) {
        $check = $checksByUrlId[$url['id']] ?? null;
        $urls[$index]['last_check_at'] = $check['last_check_at'] ?? null;
        $urls[$index]['last_status_code'] = $check['last_status_code'] ?? null;
    }

    return $renderer->render($response, 'urls/index.phtml', ['urls' => $urls]);
})->setName('urls.index');

$app->post('/urls/{url_id:[0-9]+}/checks', function ($request, $response, $args) use ($container, $routeParser) {
    $pdo = $container->get(PDO::class);
    $flash = $container->get(Messages::class);
    $client = $container->get(Client::class);

    $statement = $pdo->prepare('SELECT name FROM urls WHERE id = :id');
    $statement->execute(['id' => $args['url_id']]);
    $url = $statement->fetch();

    if ($url === false) {
        throw new HttpNotFoundException($request);
    }

    try {
        $siteResponse = $client->get($url['name']);
    } catch (GuzzleException $e) {
        $flash->addMessage('error', 'Произошла ошибка при проверке, не удалось подключиться');
        return $response
            ->withHeader('Location', $routeParser->urlFor('urls.show', ['id' => (string) $args['url_id']]))
            ->withStatus(302);
    }

    $crawler = new Crawler((string) $siteResponse->getBody());
    $h1Node = $crawler->filter('h1')->first();
    $titleNode = $crawler->filter('title')->first();
    $descriptionNode = $crawler->filter('meta[name="description"]')->first();

    $h1 = $h1Node->count() > 0 ? $h1Node->text() : null;
    $title = $titleNode->count() > 0 ? $titleNode->text() : null;
    $description = $descriptionNode->count() > 0 ? $descriptionNode->attr('content') : null;

    $statement = $pdo->prepare(
        'INSERT INTO url_checks (url_id, status_code, h1, title, description, created_at) ' .
        'VALUES (:url_id, :status_code, :h1, :title, :description, :created_at)'
    );
    $statement->execute([
        'url_id' => $args['url_id'],
        'status_code' => $siteResponse->getStatusCode(),
        'h1' => $h1,
        'title' => $title,
        'description' => $description,
        'created_at' => Carbon::now()->format('Y-m-d H:i:s'),
    ]);

    $flash->addMessage('success', 'Страница успешно проверена');
    return $response
        ->withHeader('Location', $routeParser->urlFor('urls.show', ['id' => (string) $args['url_id']]))
        ->withStatus(302);
})->setName('checks.store');

$app->get('/urls/{id:[0-9]+}', function ($request, $response, $args) use ($container) {
    $renderer = $container->get(PhpRenderer::class);
    $pdo = $container->get(PDO::class);

    $statement = $pdo->prepare('SELECT id, name, created_at FROM urls WHERE id = :id');
    $statement->execute(['id' => $args['id']]);
    $url = $statement->fetch();

    if ($url === false) {
        throw new HttpNotFoundException($request);
    }

    $statement = $pdo->prepare(
        'SELECT id, status_code, h1, title, description, created_at FROM url_checks ' .
        'WHERE url_id = :url_id ORDER BY created_at DESC'
    );
    $statement->execute(['url_id' => $args['id']]);
    $checks = $statement->fetchAll();

    return $renderer->render($response, 'urls/show.phtml', ['url' => $url, 'checks' => $checks]);
})->setName('urls.show');

$app->run();
