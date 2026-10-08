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
    private string $dbPath = '';

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bookmarks_handler_');
        self::assertIsString($path);
        $this->dbPath = $path;
        putenv('DB_PATH=' . $this->dbPath);
    }

    protected function tearDown(): void
    {
        putenv('DB_PATH');
        if ($this->dbPath !== '' && is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    private function handle(string $body): Response
    {
        $request = new Request('POST', '/api/bookmarks', $body);

        return (new CreateBookmarkHandler())($request, []);
    }

    public function testCreatesABookmarkWithNormalizedTags(): void
    {
        $response = $this->handle('{"url":"https://example.com","title":"Example","tags":[" PHP ","php"]}');

        $this->assertSame(201, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);

        $bookmark = json_decode($response->body, true);
        $this->assertIsArray($bookmark);
        $this->assertIsInt($bookmark['id']);
        $this->assertSame('https://example.com', $bookmark['url']);
        $this->assertSame('Example', $bookmark['title']);
        $this->assertSame(['php'], $bookmark['tags']);
        $this->assertSame('/api/bookmarks' . '/' . $bookmark['id'], $response->headers['Location']);
    }

    public function testInvalidUrlIsRejectedWith422NamingTheField(): void
    {
        $response = $this->handle('{"url":"not-a-url","title":"Example"}');

        $this->assertSame(422, $response->status);

        $error = json_decode($response->body, true)['error'];
        $this->assertSame('validation_failed', $error['code']);
        $this->assertSame('url', $error['details']['field']);
    }

    public function testMissingTitleIsRejectedWith422NamingTheField(): void
    {
        $response = $this->handle('{"url":"https://example.com"}');

        $this->assertSame(422, $response->status);

        $error = json_decode($response->body, true)['error'];
        $this->assertSame('title', $error['details']['field']);
    }

    public function testUnparsableJsonThrowsSoTheFrontControllerAnswers400(): void
    {
        $this->expectException(JsonException::class);

        $this->handle('{not json');
    }
}
