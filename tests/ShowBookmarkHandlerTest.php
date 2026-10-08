<?php

declare(strict_types=1);

namespace App\Tests;

use App\Handler\ShowBookmarkHandler;
use App\Http\Request;
use App\Storage\BookmarkRepository;
use App\Storage\Database;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * GET /api/bookmarks/{id} (AC-05).
 *
 * The storage slice ("Implement SQLite storage, bookmark validation and
 * creation") is a sibling ticket that may not be merged yet. While it is
 * missing, Database::connection() refuses and neither a 200 nor a real 404 can
 * be produced, so every assertion here sits behind a single readiness probe and
 * is reported as SKIPPED — never asserted against the placeholder answer. The
 * moment the storage slice lands the probe succeeds and the assertions run for
 * real, with no change to this file.
 */
final class ShowBookmarkHandlerTest extends TestCase
{
    private string $dbPath;
    private string $previousDbPath = '';
    private bool $hadDbPath = false;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/bookmarks_show_' . bin2hex(random_bytes(8)) . '.sqlite';

        $this->hadDbPath = getenv('DB_PATH') !== false;
        $this->previousDbPath = $this->hadDbPath ? (string) getenv('DB_PATH') : '';

        $this->useDatabasePath($this->dbPath);
    }

    protected function tearDown(): void
    {
        if ($this->hadDbPath) {
            $this->useDatabasePath($this->previousDbPath);
        } else {
            putenv('DB_PATH');
            unset($_ENV['DB_PATH'], $_SERVER['DB_PATH']);
        }

        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testReturnsTheStoredBookmark(): void
    {
        $this->skipUnlessStorageIsReady();

        $bookmark = $this->seedBookmark();

        $response = $this->show((string) $bookmark['id']);

        $this->assertSame(200, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);

        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded);

        $this->assertEqualsCanonicalizing(
            ['id', 'url', 'title', 'tags', 'created_at', 'updated_at'],
            array_keys($decoded)
        );
        $this->assertIsInt($decoded['id']);
        $this->assertSame($bookmark['id'], $decoded['id']);
        $this->assertIsString($decoded['url']);
        $this->assertSame('https://example.com/show', $decoded['url']);
        $this->assertIsString($decoded['title']);
        $this->assertSame('Show me', $decoded['title']);
        $this->assertIsArray($decoded['tags']);
        $this->assertSame(['one', 'two'], $decoded['tags']);
        $this->assertIsString($decoded['created_at']);
        $this->assertIsString($decoded['updated_at']);
    }

    public function testCastsTheIdParameterToInt(): void
    {
        $this->skipUnlessStorageIsReady();

        $bookmark = $this->seedBookmark();
        $nonCanonicalId = '0' . (string) $bookmark['id'];

        $response = $this->show($nonCanonicalId);

        $this->assertSame(200, $response->status);

        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded);
        $this->assertSame($bookmark['id'], $decoded['id']);
    }

    public function testUnknownIdReturns404WithErrorBody(): void
    {
        $this->skipUnlessStorageIsReady();

        $bookmark = $this->seedBookmark();

        $response = $this->show((string) ($bookmark['id'] + 100000));

        $this->assertSame(404, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
        $this->assertSame(
            '{"error":{"code":"not_found","message":"Bookmark not found","details":{}}}',
            $response->body
        );
    }

    /**
     * Readiness probe: drive the real storage stack (connection -> create ->
     * findById) against the throwaway database. While the storage slice is not
     * merged this throws, and both the found and the unknown-id assertions are
     * skipped together rather than asserted against the placeholder.
     */
    private function skipUnlessStorageIsReady(): void
    {
        try {
            $repository = new BookmarkRepository(Database::connection());
            $created = $repository->create([
                'url' => 'https://example.com/probe',
                'title' => 'storage probe',
                'tags' => [],
            ]);

            $id = $created['id'] ?? null;
            if (!is_int($id)) {
                throw new \RuntimeException('create() did not return a bookmark with an integer id');
            }

            if ($repository->findById($id) === null) {
                throw new \RuntimeException('findById() did not return the bookmark create() just stored');
            }
        } catch (Throwable $exception) {
            $this->markTestSkipped(
                'Storage slice ("Implement SQLite storage, bookmark validation and creation") '
                . 'is not available on this branch: ' . $exception->getMessage()
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function seedBookmark(): array
    {
        $repository = new BookmarkRepository(Database::connection());

        return $repository->create([
            'url' => 'https://example.com/show',
            'title' => 'Show me',
            'tags' => ['One', 'one', ' two '],
        ]);
    }

    private function show(string $id): \App\Http\Response
    {
        $handler = new ShowBookmarkHandler();

        return $handler(new Request('GET', '/api/bookmarks/' . $id), ['id' => $id]);
    }

    private function useDatabasePath(string $path): void
    {
        putenv('DB_PATH=' . $path);
        $_ENV['DB_PATH'] = $path;
        $_SERVER['DB_PATH'] = $path;
    }
}
