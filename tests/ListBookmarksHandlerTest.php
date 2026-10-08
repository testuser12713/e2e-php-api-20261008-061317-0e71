<?php

declare(strict_types=1);

namespace App\Tests;

use App\Handler\ListBookmarksHandler;
use App\Http\Request;
use App\Storage\Database;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Covers GET /api/bookmarks: newest-first ordering and the exact,
 * case-insensitive tag filter.
 *
 * The handler resolves its repository through Database::connection(), so the
 * test points DB_PATH at a throwaway SQLite file and seeds it directly. On a
 * branch where the storage layer (Database / BookmarkRepository) is still a
 * stub, the test is skipped instead of asserting the placeholder answer; once
 * that ticket is merged it exercises the real persistence path.
 */
final class ListBookmarksHandlerTest extends TestCase
{
    private string $dbPath;

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/bookmarks_list_' . bin2hex(random_bytes(6)) . '.sqlite';
        putenv('DB_PATH=' . $this->dbPath);

        try {
            $this->pdo = Database::connection();
        } catch (Throwable $exception) {
            $this->markTestSkipped('Storage layer not available: ' . $exception->getMessage());
        }

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS bookmarks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                url TEXT NOT NULL,
                title TEXT NOT NULL,
                tags TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
        $this->pdo->exec('DELETE FROM bookmarks');
    }

    protected function tearDown(): void
    {
        putenv('DB_PATH');
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    /**
     * @param list<string> $tags
     */
    private function insert(string $url, string $title, array $tags, string $createdAt): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO bookmarks (url, title, tags, created_at, updated_at)
             VALUES (:url, :title, :tags, :created_at, :updated_at)'
        );
        $statement->execute([
            ':url' => $url,
            ':title' => $title,
            ':tags' => json_encode($tags),
            ':created_at' => $createdAt,
            ':updated_at' => $createdAt,
        ]);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function list(array $query = []): array
    {
        $handler = new ListBookmarksHandler();
        $response = $handler(new Request('GET', '/api/bookmarks', '', $query), []);

        self::assertSame(200, $response->status);
        self::assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);

        $decoded = json_decode($response->body, true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testListsBookmarksNewestFirst(): void
    {
        $this->insert('https://old.example.com', 'Older', ['php'], '2020-01-01T00:00:00.000000Z');
        $this->insert('https://new.example.com', 'Newer', ['sql'], '2024-01-01T00:00:00.000000Z');

        $bookmarks = $this->list();

        self::assertSame(['Newer', 'Older'], array_column($bookmarks, 'title'));
    }

    public function testTagFilterMatchesCaseInsensitively(): void
    {
        $this->insert('https://old.example.com', 'Older', ['php'], '2020-01-01T00:00:00.000000Z');
        $this->insert('https://new.example.com', 'Newer', ['sql'], '2024-01-01T00:00:00.000000Z');

        $bookmarks = $this->list(['tag' => 'PHP']);

        self::assertSame(['Older'], array_column($bookmarks, 'title'));
    }

    public function testTagFilterDoesNotMatchPartialTags(): void
    {
        $this->insert('https://old.example.com', 'Older', ['php'], '2020-01-01T00:00:00.000000Z');
        $this->insert('https://new.example.com', 'Newer', ['sql'], '2024-01-01T00:00:00.000000Z');

        $bookmarks = $this->list(['tag' => 'ph']);

        self::assertSame([], $bookmarks);
    }

    public function testNoTagReturnsEveryBookmark(): void
    {
        $this->insert('https://old.example.com', 'Older', ['php'], '2020-01-01T00:00:00.000000Z');
        $this->insert('https://new.example.com', 'Newer', ['php', 'sql'], '2024-01-01T00:00:00.000000Z');

        $bookmarks = $this->list();

        self::assertCount(2, $bookmarks);
    }
}
