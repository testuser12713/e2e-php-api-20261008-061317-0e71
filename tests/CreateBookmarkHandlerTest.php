<?php

declare(strict_types=1);

namespace App\Tests;

use App\Handler\CreateBookmarkHandler;
use App\Http\JsonException;
use App\Http\Request;
use App\Http\Response;
use PHPUnit\Framework\TestCase;

final class CreateBookmarkHandlerTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $path = sys_get_temp_dir() . '/bookmarks_handler_' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->files[] = $path;
        putenv('DB_PATH=' . $path);
    }

    protected function tearDown(): void
    {
        putenv('DB_PATH');
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->files = [];
    }

    public function testCreatesBookmarkWithNormalizedTagAndLocation(): void
    {
        $request = new Request(
            'POST',
            '/api/bookmarks',
            '{"url":"https://example.com","title":"Example","tags":[" PHP ","php"]}'
        );

        $response = (new CreateBookmarkHandler())($request, []);

        $this->assertSame(201, $response->status);
        $this->assertArrayHasKey('Location', $response->headers);

        $bookmark = json_decode($response->body, true);
        $this->assertIsArray($bookmark);
        $this->assertSame('/api/bookmarks/' . $bookmark['id'], $response->headers['Location']);
        $this->assertSame(['php'], $bookmark['tags']);
        $this->assertSame('https://example.com', $bookmark['url']);
        $this->assertSame('Example', $bookmark['title']);
    }

    public function testInvalidUrlAnswers422WithOffendingField(): void
    {
        $request = new Request(
            'POST',
            '/api/bookmarks',
            '{"url":"not-a-url","title":"Example"}'
        );

        $response = (new CreateBookmarkHandler())($request, []);

        $this->assertSame(422, $response->status);

        $body = json_decode($response->body, true);
        $this->assertSame('validation_failed', $body['error']['code']);
        $this->assertSame('url', $body['error']['details']['field']);
    }

    public function testMissingTitleAnswers422WithOffendingField(): void
    {
        $request = new Request('POST', '/api/bookmarks', '{"url":"https://example.com"}');

        $response = (new CreateBookmarkHandler())($request, []);

        $this->assertSame(422, $response->status);

        $body = json_decode($response->body, true);
        $this->assertSame('title', $body['error']['details']['field']);
    }

    public function testUnparsableJsonIsMappedToBadRequest(): void
    {
        $request = new Request('POST', '/api/bookmarks', '{not json');

        $response = $this->dispatchLikeFrontController($request);

        $this->assertSame(400, $response->status);

        $body = json_decode($response->body, true);
        $this->assertSame('bad_request', $body['error']['code']);
    }

    /**
     * Mirror the front controller's JsonException mapping.
     */
    private function dispatchLikeFrontController(Request $request): Response
    {
        try {
            return (new CreateBookmarkHandler())($request, []);
        } catch (JsonException $exception) {
            return Response::error(400, 'bad_request', $exception->getMessage());
        }
    }
}
