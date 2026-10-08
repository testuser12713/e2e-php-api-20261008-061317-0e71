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
    private string $databasePath = '';

    protected function setUp(): void
    {
        $this->databasePath = sys_get_temp_dir() . '/bookmarks_create_' . bin2hex(random_bytes(8)) . '.sqlite';
        putenv('DB_PATH=' . $this->databasePath);
    }

    protected function tearDown(): void
    {
        putenv('DB_PATH');
        if ($this->databasePath !== '' && is_file($this->databasePath)) {
            unlink($this->databasePath);
        }
    }

    /**
     * Dispatch through the handler, mapping JsonException to 400 exactly as the
     * front controller does, so the behaviour is observable in-process.
     *
     * @param array<string, mixed> $params
     */
    private function dispatch(Request $request, array $params = []): Response
    {
        try {
            return (new CreateBookmarkHandler())($request, $params);
        } catch (JsonException $exception) {
            return Response::error(400, 'bad_request', $exception->getMessage());
        }
    }

    private function post(string $body): Response
    {
        return $this->dispatch(new Request('POST', '/api/bookmarks', $body));
    }

    public function testCreatesABookmarkWith201AndLocation(): void
    {
        $response = $this->post(json_encode([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => [' PHP ', 'php'],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame(201, $response->status);
        $this->assertArrayHasKey('Location', $response->headers);
        $this->assertSame('/api/bookmarks/1', $response->headers['Location']);

        $bookmark = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $bookmark['id']);
        $this->assertSame('https://example.com', $bookmark['url']);
        $this->assertSame('Example', $bookmark['title']);
        $this->assertSame(['php'], $bookmark['tags']);
    }

    public function testInvalidUrlAnswers422NamingTheField(): void
    {
        $response = $this->post('{"url":"not-a-url","title":"Example"}');

        $this->assertSame(422, $response->status);

        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('validation_failed', $body['error']['code']);
        $this->assertSame('url', $body['error']['details']['field']);
    }

    public function testUnparsableJsonAnswers400(): void
    {
        $response = $this->post('{not json');

        $this->assertSame(400, $response->status);

        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('bad_request', $body['error']['code']);
    }
}
