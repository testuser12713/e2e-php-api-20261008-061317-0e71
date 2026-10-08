<?php

declare(strict_types=1);

namespace App\Tests;

use App\Handler\CreateBookmarkHandler;
use App\Http\Request;
use PHPUnit\Framework\TestCase;

final class CreateBookmarkHandlerTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/create_handler_' . bin2hex(random_bytes(8)) . '.sqlite';
        putenv('DB_PATH=' . $this->path);
    }

    protected function tearDown(): void
    {
        putenv('DB_PATH');
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    private function request(string $body): Request
    {
        return new Request('POST', '/api/bookmarks', $body);
    }

    public function testCreatesABookmarkAndReturns201WithLocation(): void
    {
        $handler = new CreateBookmarkHandler();
        $request = $this->request(json_encode([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => [' PHP ', 'php'],
        ]) ?: '');

        $response = $handler($request, []);

        $this->assertSame(201, $response->status);
        $this->assertSame('/api/bookmarks/1', $response->headers['Location']);

        $data = json_decode($response->body, true);
        $this->assertIsArray($data);
        $this->assertSame(1, $data['id']);
        $this->assertSame('https://example.com', $data['url']);
        $this->assertSame('Example', $data['title']);
        $this->assertSame(['php'], $data['tags']);
        $this->assertArrayHasKey('created_at', $data);
        $this->assertArrayHasKey('updated_at', $data);
    }

    public function testRejectsAnInvalidUrlWith422(): void
    {
        $handler = new CreateBookmarkHandler();
        $request = $this->request(json_encode([
            'url' => 'not-a-url',
            'title' => 'Example',
        ]) ?: '');

        $response = $handler($request, []);

        $this->assertSame(422, $response->status);

        $data = json_decode($response->body, true);
        $this->assertIsArray($data);
        $this->assertSame('validation_failed', $data['error']['code']);
        $this->assertSame('url', $data['error']['details']['field']);
    }

    public function testRejectsAMissingTitleWith422(): void
    {
        $handler = new CreateBookmarkHandler();
        $request = $this->request(json_encode([
            'url' => 'https://example.com',
        ]) ?: '');

        $response = $handler($request, []);

        $this->assertSame(422, $response->status);

        $data = json_decode($response->body, true);
        $this->assertIsArray($data);
        $this->assertSame('title', $data['error']['details']['field']);
    }

    public function testRejectsUnparsableJsonWith400(): void
    {
        $handler = new CreateBookmarkHandler();
        $request = $this->request('{not json');

        $response = $handler($request, []);

        $this->assertSame(400, $response->status);

        $data = json_decode($response->body, true);
        $this->assertIsArray($data);
        $this->assertSame('bad_request', $data['error']['code']);
    }
}
