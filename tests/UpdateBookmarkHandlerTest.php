<?php

declare(strict_types=1);

namespace App\Tests;

use App\Handler\UpdateBookmarkHandler;
use App\Http\Request;
use App\Http\Response;
use PHPUnit\Framework\TestCase;

final class UpdateBookmarkHandlerTest extends TestCase
{
    private string $dbPath = '';
    private bool $dbPathCreated = false;

    protected function setUp(): void
    {
        $dbPath = tempnam(sys_get_temp_dir(), 'bookmarks_update_');
        if ($dbPath !== false) {
            $this->dbPath = $dbPath;
            $this->dbPathCreated = true;
            putenv('DB_PATH=' . $dbPath);
            $_ENV['DB_PATH'] = $dbPath;
            $_SERVER['DB_PATH'] = $dbPath;
        }
    }

    protected function tearDown(): void
    {
        putenv('DB_PATH');
        unset($_ENV['DB_PATH'], $_SERVER['DB_PATH']);

        if ($this->dbPathCreated && $this->dbPath !== '' && is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function bookmark(int $id, string $title, string $updatedAt): array
    {
        return [
            'id' => $id,
            'url' => 'https://example.com',
            'title' => $title,
            'tags' => [],
            'created_at' => '2024-01-01T00:00:00Z',
            'updated_at' => $updatedAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function validRequest(string $method, string $id, string $body): Request
    {
        return new Request($method, '/api/bookmarks/' . $id, $body);
    }

    public function testUpdatesTitle(): void
    {
        $calls = [];
        $update = function (int $id, array $data) use (&$calls): ?array {
            $calls[] = [$id, $data];

            return $this->bookmark($id, (string) $data['title'], '2024-02-02T00:00:00Z');
        };
        $handler = new UpdateBookmarkHandler(
            $update,
            static fn (array $input, bool $partial): array => []
        );

        $response = $handler(
            $this->validRequest('PATCH', '7', '{"title":"New title"}'),
            ['id' => '7']
        );

        $this->assertSame(200, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);

        $data = $this->decode($response);
        $this->assertSame(7, $data['id']);
        $this->assertSame('New title', $data['title']);
        $this->assertSame('2024-02-02T00:00:00Z', $data['updated_at']);

        $this->assertSame([[7, ['title' => 'New title']]], $calls);
    }

    public function testUpdatesUrlWithPut(): void
    {
        $update = function (int $id, array $data): ?array {
            $bookmark = $this->bookmark($id, 'Keep', '2024-03-03T00:00:00Z');
            $bookmark['url'] = (string) $data['url'];

            return $bookmark;
        };
        $handler = new UpdateBookmarkHandler(
            $update,
            static fn (array $input, bool $partial): array => []
        );

        $response = $handler(
            $this->validRequest('PUT', '12', '{"url":"https://example.org"}'),
            ['id' => '12']
        );

        $this->assertSame(200, $response->status);
        $data = $this->decode($response);
        $this->assertSame('https://example.org', $data['url']);
    }

    public function testUnknownIdAnswers404(): void
    {
        $update = static fn (int $id, array $data): ?array => null;
        $handler = new UpdateBookmarkHandler(
            $update,
            static fn (array $input, bool $partial): array => []
        );

        $response = $handler(
            $this->validRequest('PATCH', '999', '{"title":"Whatever"}'),
            ['id' => '999']
        );

        $this->assertSame(404, $response->status);
        $data = $this->decode($response);
        $this->assertSame('not_found', $data['error']['code']);
    }

    public function testInvalidUrlAnswers422WithField(): void
    {
        $updateCalled = false;
        $update = function (int $id, array $data) use (&$updateCalled): ?array {
            $updateCalled = true;

            return $this->bookmark($id, 'x', '2024-04-04T00:00:00Z');
        };

        $seen = [];
        $validate = function (array $input, bool $partial) use (&$seen): array {
            $seen[] = [$input, $partial];

            return [['field' => 'url', 'message' => 'url must be a valid http(s) URL']];
        };
        $handler = new UpdateBookmarkHandler($update, $validate);

        $response = $handler(
            $this->validRequest('PUT', '3', '{"url":"not a url"}'),
            ['id' => '3']
        );

        $this->assertSame(422, $response->status);
        $data = $this->decode($response);
        $this->assertSame('validation_failed', $data['error']['code']);
        $this->assertSame('url', $data['error']['details']['field']);

        $this->assertFalse($updateCalled, 'the repository must not be touched when validation fails');
        $this->assertSame([[['url' => 'not a url'], true]], $seen, 'validation must run in partial mode');
    }

    public function testEmptyBodyAnswers400(): void
    {
        $handler = new UpdateBookmarkHandler(
            static fn (int $id, array $data): ?array => null,
            static fn (array $input, bool $partial): array => []
        );

        $request = new Request('PATCH', '/api/bookmarks/1', '');

        $this->expectException(\App\Http\JsonException::class);
        $handler($request, ['id' => '1']);
    }
}
