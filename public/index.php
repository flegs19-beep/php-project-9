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

session_start();

$databaseUrl = parse_url(getenv('DATABASE_URL'));

$dsn = sprintf(
    'pgsql:host=%s;port=%s;dbname=%s',
    $databaseUrl['host'],
    $databaseUrl['port'] ?? 5432,
    ltrim($databaseUrl['path'], '/')
);

$pdo = new PDO(
    $dsn,
    rawurldecode($databaseUrl['user']),
    rawurldecode($databaseUrl['pass']),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

$app = AppFactory::create();

$renderer = new PhpRenderer(__DIR__ . '/../templates');
$renderer->setLayout('layout.phtml');

$flash = new Messages();
$routeParser = $app->getRouteCollector()->getRouteParser();

$client = new Client([
    'timeout' => 10,
]);

$app->get('/', function ($request, $response) use ($renderer, $flash) {
    return $renderer->render($response, 'index.phtml', [
        'flash' => $flash->getMessages(),
    ]);
})->setName('home');

$app->post('/urls', function ($request, $response) use ($renderer, $pdo, $flash, $routeParser) {
    $data = (array) $request->getParsedBody();
    $url = trim($data['url'] ?? '');

    $validator = new Validator(['url' => $url]);
    $validator->rule('required', 'url')->message('URL не должен быть пустым');
    $validator->rule('url', 'url')->message('Некорректный URL');
    $validator->rule('lengthMax', 'url', 255)->message('URL превышает 255 символов');

    if (!$validator->validate()) {
        return $renderer->render($response->withStatus(422), 'index.phtml', [
            'errors' => $validator->errors(),
            'url' => $url,
        ]);
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
            ->withHeader('Location', $routeParser->urlFor('urls.show', [
                'id' => (string) $existingUrl['id'],
            ]))
            ->withStatus(302);
    }

    $statement = $pdo->prepare(
        'INSERT INTO urls (name, created_at) VALUES (:name, :created_at) RETURNING id'
    );

    $statement->execute([
        'name' => $name,
        'created_at' => Carbon::now()->format('Y-m-d H:i:s'),
    ]);

    $id = $statement->fetchColumn();

    $flash->addMessage('success', 'Страница успешно добавлена');

    return $response
        ->withHeader('Location', $routeParser->urlFor('urls.show', [
            'id' => (string) $id,
        ]))
        ->withStatus(302);
})->setName('urls.store');

$app->get('/urls', function ($request, $response) use ($renderer, $pdo, $flash) {
    $statement = $pdo->query(
        'SELECT urls.id, urls.name, urls.created_at,
            (SELECT created_at
            FROM url_checks
            WHERE url_checks.url_id = urls.id
            ORDER BY created_at DESC, id DESC
            LIMIT 1) AS last_check_at,
            (SELECT status_code
            FROM url_checks
            WHERE url_checks.url_id = urls.id
            ORDER BY created_at DESC, id DESC
            LIMIT 1) AS last_status_code
        FROM urls
        ORDER BY urls.created_at DESC, urls.id DESC'
    );

    $urls = $statement->fetchAll();

    return $renderer->render($response, 'urls.phtml', [
        'urls' => $urls,
        'flash' => $flash->getMessages(),
    ]);
})->setName('urls.index');


$app->post(
    '/urls/{url_id}/checks',
    function (
        $request,
        $response,
        $args
    ) use (
        $pdo,
        $flash,
        $routeParser,
        $client
    ) {
        $statement = $pdo->prepare(
            'SELECT name FROM urls WHERE id = :id'
        );
        $statement->execute(['id' => $args['url_id']]);

        $url = $statement->fetch();

        if ($url === false) {
            $response->getBody()->write('Page not found');

            return $response->withStatus(404);
        }

        try {
            $siteResponse = $client->get($url['name']);
        } catch (GuzzleException $e) {
            $flash->addMessage(
                'error',
                'Произошла ошибка при проверке, не удалось подключиться'
            );

            return $response
                ->withHeader('Location', $routeParser->urlFor('urls.show', [
                    'id' => (string) $args['url_id'],
                ]))
                ->withStatus(302);
        }

        $crawler = new Crawler((string) $siteResponse->getBody());

        $h1Node = $crawler->filter('h1')->first();
        $titleNode = $crawler->filter('title')->first();
        $descriptionNode = $crawler->filter('meta[name="description"]')->first();

        $h1 = $h1Node->count() > 0 ? $h1Node->text() : null;
        $title = $titleNode->count() > 0 ? $titleNode->text() : null;
        $description = $descriptionNode->count() > 0
            ? $descriptionNode->attr('content')
            : null;

        $statement = $pdo->prepare(
            'INSERT INTO url_checks
            (url_id, status_code, h1, title, description, created_at)
            VALUES
            (:url_id, :status_code, :h1, :title, :description, :created_at)'
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
            ->withHeader('Location', $routeParser->urlFor('urls.show', [
                'id' => (string) $args['url_id'],
            ]))
            ->withStatus(302);
    }
)->setName('checks.store');


$app->get('/urls/{id}', function ($request, $response, $args) use ($renderer, $pdo, $flash) {
    $statement = $pdo->prepare('SELECT id, name, created_at FROM urls WHERE id = :id');
    $statement->execute(['id' => $args['id']]);

    $url = $statement->fetch();

    if ($url === false) {
        $response->getBody()->write('Page not found');

        return $response->withStatus(404);
    }

    $statement = $pdo->prepare(
        'SELECT id, status_code, h1, title, description, created_at
        FROM url_checks
        WHERE url_id = :url_id
        ORDER BY created_at DESC'
    );

    $statement->execute(['url_id' => $args['id']]);

    $checks = $statement->fetchAll();

    return $renderer->render($response, 'url.phtml', [
        'url' => $url,
        'checks' => $checks,
        'flash' => $flash->getMessages(),
    ]);
})->setName('urls.show');

$app->run();
