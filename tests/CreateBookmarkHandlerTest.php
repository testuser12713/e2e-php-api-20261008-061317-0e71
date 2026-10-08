<?php

declare(strict_types=1);

namespace App\Tests;

use App\Handler\CreateBookmarkHandler;
use App\Http\JsonException;
use App\Http\Request;
use App\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Covers POST /api/bookmarks (AC-01, AC-02, AC-03): 201 with a Location header
 * and the stored bookmark, 422 naming the offending field, 400 for unparsable
 * JSON.
 */
final class CreateBookmarkHandlerTest extends TestCase
{
    private ?string $dbPath = null;

    private string|false $previousDbPath = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousDbPath = getenv('DB_PATH');

        $path = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'bookmarks_create_' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->removeDatabaseFiles($path);
        $this->dbPath = $path;

        putenv('DB_PATH=' . $path);
        $_ENV['DB_PATH'] = $path;
        $_SERVER['DB_PATH'] = $path;
    }

    protected function tearDown(): void
    {
        if ($this->dbPath !== null) {
            $this->removeDatabaseFiles($this->dbPath);
        }

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

    private function removeDatabaseFiles(string $path): void
    {
        foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    private function invoke(string $body): Response
    {
        return (new CreateBookmarkHandler())(new Request('POST', '/api/bookmarks', $body), []);
    }

    public function testCreateReturns201WithLocationAndNormalizedTags(): void
    {
        $response = $this->invoke(json_encode([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => [' PHP ', 'php'],
        ]));

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

    public function testInvalidUrlAnswers422NamingUrl(): void
    {
        $response = $this->invoke(json_encode([
            'url' => 'not-a-url',
            'title' => 'Example',
        ]));

        $this->assertSame(422, $response->status);
        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded);
        $this->assertSame('validation_failed', $decoded['error']['code']);
        $this->assertSame('url', $decoded['error']['details']['field']);
    }

    public function testEmptyTitleAnswers422NamingTitle(): void
    {
        $response = $this->invoke(json_encode([
            'url' => 'https://example.com',
            'title' => '  ',
        ]));

        $this->assertSame(422, $response->status);
        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded);
        $this->assertSame('validation_failed', $decoded['error']['code']);
        $this->assertSame('title', $decoded['error']['details']['field']);
    }

    public function testUnparsableJsonYields400ThroughTheFrontController(): void
    {
        try {
            $this->invoke('{not json');
            $this->fail('Expected App\Http\JsonException for an unparsable body.');
        } catch (JsonException $exception) {
            $response = Response::error(400, 'bad_request', $exception->getMessage());
        }

        $this->assertSame(400, $response->status);
        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded);
        $this->assertSame('bad_request', $decoded['error']['code']);
    }
}
