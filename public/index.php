<?php

declare(strict_types=1);

use App\Handler\CreateBookmarkHandler;
use App\Handler\DeleteBookmarkHandler;
use App\Handler\ListBookmarksHandler;
use App\Handler\ShowBookmarkHandler;
use App\Handler\UpdateBookmarkHandler;
use App\Http\JsonException;
use App\Http\Request;
use App\Http\Response;
use App\Router;

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

require __DIR__ . '/../vendor/autoload.php';

$GLOBALS['app_response_sent'] = false;

/**
 * Send a response exactly once, so neither an exception handler nor the
 * shutdown handler can emit a second (HTML) body after the real one.
 */
function app_send(Response $response): void
{
    if ($GLOBALS['app_response_sent'] === true) {
        return;
    }
    $GLOBALS['app_response_sent'] = true;
    $response->send();
}

set_exception_handler(static function (Throwable $exception): void {
    if ($exception instanceof JsonException) {
        app_send(Response::error(400, 'bad_request', $exception->getMessage()));

        return;
    }

    error_log(sprintf(
        'Uncaught %s: %s in %s:%d',
        $exception::class,
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));
    app_send(Response::error(500, 'internal_error', 'Internal Server Error'));
});

register_shutdown_function(static function (): void {
    if ($GLOBALS['app_response_sent'] === true) {
        return;
    }

    $error = error_get_last();
    $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
    if ($error === null || ($error['type'] & $fatal) === 0) {
        return;
    }

    error_log(sprintf('Fatal error: %s in %s:%d', $error['message'], $error['file'], $error['line']));
    app_send(Response::error(500, 'internal_error', 'Internal Server Error'));
});

$router = new Router();

$router->add('GET', '/api/health', static function (Request $request, array $params): Response {
    return Response::json(['status' => 'ok']);
});
$router->add('POST', '/api/bookmarks', new CreateBookmarkHandler());
$router->add('GET', '/api/bookmarks', new ListBookmarksHandler());
$router->add('GET', '/api/bookmarks/{id}', new ShowBookmarkHandler());
$router->add('PUT', '/api/bookmarks/{id}', new UpdateBookmarkHandler());
$router->add('PATCH', '/api/bookmarks/{id}', new UpdateBookmarkHandler());
$router->add('DELETE', '/api/bookmarks/{id}', new DeleteBookmarkHandler());

$request = Request::fromGlobals();

try {
    $response = $router->dispatch($request);
} catch (JsonException $exception) {
    $response = Response::error(400, 'bad_request', $exception->getMessage());
} catch (Throwable $exception) {
    error_log(sprintf(
        'Uncaught %s: %s in %s:%d',
        $exception::class,
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));
    $response = Response::error(500, 'internal_error', 'Internal Server Error');
}

app_send($response);
