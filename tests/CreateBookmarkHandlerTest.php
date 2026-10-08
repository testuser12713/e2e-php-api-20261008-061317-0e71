<?php

declare(strict_types=1);

namespace App\Tests;

use App\Handler\CreateBookmarkHandler;
use App\Http\JsonException;
use App\Http\Request;
use App\Http\Response;
use App\Storage\Database;
use PHPUnit\Framework\TestCase;

final class CreateBookmarkHandlerTest extends TestCase
{
    private string $dir;
    private string $path;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/bookmarks_create_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
        $this->path = $this->dir . '/bookmarks.sqlite';
        putenv('DB_PATH=' . $this->path);

        // Build the schema exactly as the front controller would on first request.
        Database::connection();
    }

    protected function tearDown(): void
    {
        putenv('DB_PATH');
        if (is_file($this->path)) {
            unlink($this->path);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    /**
     * Dispatch through the handler with the front controller's JSON error
     * mapping, so an unparsable body is observable as a 400 here too.
     */
    private function dispatch(Request $request): Response
    {
        try {
            return (new CreateBookmarkHandler())($request, []);
        } catch (JsonException $exception) {
            return Response::error(400, 'bad_request', $exception->getMessage());
        }
    }

    public function testCreateReturns201WithLocationAndNormalizedTag(): void
    {
        $request = new Request(
            'POST',
            '/api/bookmarks',
            '{"url":"https://example.com","title":"Example","tags":[" PHP ","php"]}'
        );

        $response = $this->dispatch($request);

        $this->assertSame(201, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
        $this->assertArrayHasKey('Location', $response->headers);

        $bookmark = json_decode($response->body, true);
        $this->assertIsArray($bookmark);
        $this->assertSame('/api/bookmarks' . '/' . $bookmark['id'], $response->headers['Location']);
        $this->assertSame('https://example.com', $bookmark['url']);
        $this->assertSame('Example', $bookmark['title']);
        $this->assertSame(['php'], $bookmark['tags']);
        $this->assertIsInt($bookmark['id']);
    }

    public function testInvalidUrlReturns422NamingTheField(): void
    {
        $request = new Request(
            'POST',
            '/api/bookmarks',
            '{"url":"not-a-url","title":"Example"}'
        );

        $response = $this->dispatch($request);

        $this->assertSame(422, $response->status);
        $body = json_decode($response->body, true);
        $this->assertSame('validation_failed', $body['error']['code']);
        $this->assertSame('url', $body['error']['details']['field']);
    }

    public function testMissingTitleReturns422NamingTheField(): void
    {
        $request = new Request(
            'POST',
            '/api/bookmarks',
            '{"url":"https://example.com"}'
        );

        $response = $this->dispatch($request);

        $this->assertSame(422, $response->status);
        $body = json_decode($response->body, true);
        $this->assertSame('title', $body['error']['details']['field']);
    }

    public function testUnparsableJsonReturns400(): void
    {
        $request = new Request('POST', '/api/bookmarks', '{not json');

        $response = $this->dispatch($request);

        $this->assertSame(400, $response->status);
        $body = json_decode($response->body, true);
        $this->assertSame('bad_request', $body['error']['code']);
    }
}
