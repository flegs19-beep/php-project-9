<?php

require __DIR__ . '/../vendor/autoload.php';

use Carbon\Carbon;
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
        'SELECT id, name, created_at FROM urls ORDER BY created_at DESC'
    );

    $urls = $statement->fetchAll();

    return $renderer->render($response, 'urls.phtml', [
        'urls' => $urls,
        'flash' => $flash->getMessages(),
    ]);
})->setName('urls.index');

$app->get('/urls/{id}', function ($request, $response, $args) use ($renderer, $pdo, $flash) {
    $statement = $pdo->prepare('SELECT id, name, created_at FROM urls WHERE id = :id');
    $statement->execute(['id' => $args['id']]);

    $url = $statement->fetch();

    if ($url === false) {
        $response->getBody()->write('Page not found');

        return $response->withStatus(404);
    }

    return $renderer->render($response, 'url.phtml', [
        'url' => $url,
        'flash' => $flash->getMessages(),
    ]);
})->setName('urls.show');

$app->run();
