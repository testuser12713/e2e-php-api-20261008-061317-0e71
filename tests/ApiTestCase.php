<?php

declare(strict_types=1);

namespace App\Tests;

use App\Handler\CreateBookmarkHandler;
use App\Handler\DeleteBookmarkHandler;
use App\Handler\ListBookmarksHandler;
use App\Handler\ShowBookmarkHandler;
use App\Handler\UpdateBookmarkHandler;
use App\Http\JsonException;
use App\Http\Request;
use App\Http\Response;
use App\Router;
use PHPUnit\Framework\TestCase;

/**
 * Base class for end-to-end API tests.
 *
 * It dispatches requests in-process through the same router and handler wiring
 * as public/index.php, and it points DB_PATH at a throwaway SQLite file for
 * every test so the suite never touches the real database. The handler wiring
 * mirrors public/index.php line for line: a route that is registered there is
 * reachable here.
 */
abstract class ApiTestCase extends TestCase
{
    private Router $router;

    private string $dbPath;

    private string|false $previousDbPath = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousDbPath = getenv('DB_PATH');

        $this->dbPath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'bookmarks_api_' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->removeDatabaseFiles($this->dbPath);

        putenv('DB_PATH=' . $this->dbPath);
        $_ENV['DB_PATH'] = $this->dbPath;
        $_SERVER['DB_PATH'] = $this->dbPath;

        $this->router = $this->buildRouter();
    }

    protected function tearDown(): void
    {
        $this->removeDatabaseFiles($this->dbPath);

        if ($this->previousDbPath === false || $this->previousDbPath === '') {
            putenv('DB_PATH');
            unset($_ENV['DB_PATH'], $_SERVER['DB_PATH']);
        } else {
            putenv('DB_PATH=' . $this->previousDbPath);
            $_ENV['DB_PATH'] = $this->previousDbPath;
            $_SERVER['DB_PATH'] = $this->previousDbPath;
        }

        parent::tearDown();
    }

    /**
     * The discardable SQLite file backing the current test.
     */
    protected function dbPath(): string
    {
        return $this->dbPath;
    }

    /**
     * Dispatch a request through the in-process front controller and return the
     * status, the decoded JSON body (null when the body is empty or not JSON)
     * and the response headers, keyed by their original names.
     *
     * @param array<string, mixed>|string|null $body
     * @param array<string, string>            $headers
     * @param array<string, mixed>             $query
     *
     * @return array{status: int, body: array<string, mixed>|null, headers: array<string, string>, raw: string}
     */
    protected function request(
        string $method,
        string $path,
        array|string|null $body = null,
        array $headers = [],
        array $query = [],
    ): array {
        $raw = '';
        if (is_string($body)) {
            $raw = $body;
        } elseif (is_array($body)) {
            $encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $raw = $encoded === false ? '' : $encoded;
        }

        $request = new Request(strtoupper($method), $path, $raw, $query, $headers);

        try {
            $response = $this->router->dispatch($request);
        } catch (JsonException $exception) {
            $response = Response::error(400, 'bad_request', $exception->getMessage());
        }

        $decoded = json_decode($response->body, true);

        return [
            'status' => $response->status,
            'body' => is_array($decoded) ? $decoded : null,
            'headers' => $response->headers,
            'raw' => $response->body,
        ];
    }

    /**
     * Mirror of the route table built by public/index.php.
     */
    private function buildRouter(): Router
    {
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

        return $router;
    }

    private function removeDatabaseFiles(string $path): void
    {
        foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
}
