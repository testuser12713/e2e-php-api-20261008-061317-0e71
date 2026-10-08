<?php

declare(strict_types=1);

namespace App\Tests;

use App\Handler\CreateBookmarkHandler;
use App\Http\JsonException;
use App\Http\Request;
use App\Http\Response;
use App\Storage\BookmarkRepository;
use App\Storage\Database;
use PHPUnit\Framework\TestCase;

/**
 * Covers POST /api/bookmarks (AC-01, AC-02, AC-03).
 */
final class CreateBookmarkHandlerTest extends TestCase
{
    private string $dbPath;

    private string|false $previousDbPath = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousDbPath = getenv('DB_PATH');

        $this->dbPath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'bookmarks_create_' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->removeDatabaseFiles($this->dbPath);

        putenv('DB_PATH=' . $this->dbPath);
        $_ENV['DB_PATH'] = $this->dbPath;
        $_SERVER['DB_PATH'] = $this->dbPath;
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
        $request = new Request('POST', '/api/bookmarks', $body);

        return (new CreateBookmarkHandler())($request, []);
    }

    public function testValidBodyCreatesBookmarkWithLocationAndNormalizedTags(): void
    {
        $response = $this->invoke(
            '{"url":"https://example.com","title":"Example","tags":[" PHP ","php"]}'
        );

        $this->assertSame(201, $response->status);
        $this->assertArrayHasKey('Location', $response->headers);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);

        $bookmark = json_decode($response->body, true);
        $this->assertIsArray($bookmark);
        $this->assertSame('/api/bookmarks' . '/' . $bookmark['id'], $response->headers['Location']);
        $this->assertIsInt($bookmark['id']);
        $this->assertSame('https://example.com', $bookmark['url']);
        $this->assertSame('Example', $bookmark['title']);
        $this->assertSame(['php'], $bookmark['tags']);
        $this->assertNotSame('', $bookmark['created_at']);
        $this->assertNotSame('', $bookmark['updated_at']);
    }

    public function testCreatedBookmarkIsReadableFromStorage(): void
    {
        $response = $this->invoke('{"url":"https://example.com","title":"Example"}');
        $bookmark = json_decode($response->body, true);

        $stored = (new BookmarkRepository(Database::connection()))->findById((int) $bookmark['id']);

        $this->assertIsArray($stored);
        $this->assertSame('Example', $stored['title']);
    }

    public function testInvalidUrlAnswers422NamingTheField(): void
    {
        $response = $this->invoke('{"url":"not-a-url","title":"Example"}');

        $this->assertSame(422, $response->status);
        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded);
        $this->assertSame('validation_failed', $decoded['error']['code']);
        $this->assertSame('url', $decoded['error']['details']['field']);
    }

    public function testMissingTitleAnswers422NamingTheField(): void
    {
        $response = $this->invoke('{"url":"https://example.com"}');

        $this->assertSame(422, $response->status);
        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded);
        $this->assertSame('validation_failed', $decoded['error']['code']);
        $this->assertSame('title', $decoded['error']['details']['field']);
    }

    public function testUnparsableBodyThrowsJsonExceptionWhichTheFrontControllerMapsTo400(): void
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
