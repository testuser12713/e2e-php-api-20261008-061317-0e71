<?php

declare(strict_types=1);

namespace App\Tests;

use App\Handler\CreateBookmarkHandler;
use App\Http\JsonException;
use App\Http\Request;
use PHPUnit\Framework\TestCase;

final class CreateBookmarkHandlerTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/bookmarks_handler_' . bin2hex(random_bytes(6)) . '.sqlite';
        putenv('DB_PATH=' . $this->dbPath);
    }

    protected function tearDown(): void
    {
        putenv('DB_PATH');
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(array $payload): \App\Http\Response
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $request = new Request('POST', '/api/bookmarks', $body === false ? '' : $body);

        return (new CreateBookmarkHandler())($request, []);
    }

    public function testReturns201WithLocationAndStoredBookmark(): void
    {
        $response = $this->post(['url' => 'https://example.com', 'title' => 'Example', 'tags' => ['php']]);

        $this->assertSame(201, $response->status);
        $this->assertArrayHasKey('Location', $response->headers);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);

        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertIsInt($body['id']);
        $this->assertSame('https://example.com', $body['url']);
        $this->assertSame('Example', $body['title']);
        $this->assertSame(['php'], $body['tags']);
        $this->assertNotSame('', $body['created_at']);
        $this->assertNotSame('', $body['updated_at']);
        $this->assertSame(
            str_replace('{id}', (string) $body['id'], '/api/bookmarks/{id}'),
            $response->headers['Location']
        );
    }

    public function testCollapsesDuplicateTagsToASingleNormalizedTag(): void
    {
        $response = $this->post([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => [' PHP ', 'php'],
        ]);

        $this->assertSame(201, $response->status);

        $body = json_decode($response->body, true);
        $this->assertSame(['php'], $body['tags']);
    }

    public function testInvalidUrlIsRejectedWith422NamingTheField(): void
    {
        $response = $this->post(['url' => 'not-a-url', 'title' => 'Example']);

        $this->assertSame(422, $response->status);

        $body = json_decode($response->body, true);
        $this->assertSame('validation_failed', $body['error']['code']);
        $this->assertSame('url', $body['error']['details']['field']);
    }

    public function testMissingTitleIsRejectedWith422NamingTheField(): void
    {
        $response = $this->post(['url' => 'https://example.com']);

        $this->assertSame(422, $response->status);

        $body = json_decode($response->body, true);
        $this->assertSame('validation_failed', $body['error']['code']);
        $this->assertSame('title', $body['error']['details']['field']);
    }

    public function testUnparsableJsonThrowsJsonExceptionForTheFrontControllerToMapTo400(): void
    {
        $request = new Request('POST', '/api/bookmarks', '{not json');

        $this->expectException(JsonException::class);

        (new CreateBookmarkHandler())($request, []);
    }
}
