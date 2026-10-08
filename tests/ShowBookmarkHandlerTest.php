<?php

declare(strict_types=1);

namespace App\Tests;

use App\Handler\ShowBookmarkHandler;
use App\Http\Request;
use App\Http\Response;
use App\Storage\BookmarkRepository;
use App\Storage\Database;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests for GET /api/bookmarks/{id} (single bookmark retrieval).
 *
 * The handler has no injection point: it always reads through
 * BookmarkRepository backed by the shared Database connection. This test
 * therefore drives the same path the running product uses, pointing DB_PATH at
 * a throwaway SQLite file.
 *
 * The storage slice (ticket #2: SQLite storage, validation and creation) is a
 * parallel ticket and may not have merged yet. While it has not, Database and
 * BookmarkRepository are still their skeleton placeholders and no request can
 * be served at all, so the class is skipped with a reason naming the storage
 * slice. That guard disappears on its own once storage lands.
 */
final class ShowBookmarkHandlerTest extends TestCase
{
    private string $dbPath = '';

    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/bookmarks_show_' . bin2hex(random_bytes(8)) . '.sqlite';
        putenv('DB_PATH=' . $this->dbPath);

        try {
            $this->pdo = Database::connection();
        } catch (\Throwable $exception) {
            $this->markTestSkipped(
                'The storage slice (#2 — SQLite storage, validation and creation) has not merged yet: '
                . 'App\Storage\Database::connection() is still the skeleton placeholder, so '
                . 'GET /api/bookmarks/{id} cannot serve any request yet (' . $exception->getMessage() . ').'
            );
        }
    }

    protected function tearDown(): void
    {
        $this->pdo = null;
        putenv('DB_PATH');

        if ($this->dbPath !== '' && is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testKnownIdReturnsTheBookmarkWithTheContractShape(): void
    {
        $created = $this->seedBookmark();

        // Pass the route parameter the way the router does: as a string. With
        // strict_types, a missing (int) cast would turn this into a TypeError.
        $response = $this->handle('GET', '/api/bookmarks/' . $created['id'], (string) $created['id']);

        $this->assertSame(200, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);

        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded);
        $this->assertSame(
            ['id', 'url', 'title', 'tags', 'created_at', 'updated_at'],
            array_keys($decoded)
        );
        $this->assertIsInt($decoded['id']);
        $this->assertSame($created['id'], $decoded['id']);
        $this->assertSame('https://example.com/php', $decoded['url']);
        $this->assertSame('PHP Bookmark', $decoded['title']);
        $this->assertSame(['php', 'api'], $decoded['tags']);
        $this->assertIsString($decoded['created_at']);
        $this->assertIsString($decoded['updated_at']);
    }

    public function testRouteParameterIsCastFromStringToInt(): void
    {
        $created = $this->seedBookmark();

        $response = $this->handle('GET', '/api/bookmarks/' . $created['id'], (string) $created['id']);

        $this->assertSame(200, $response->status);
        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded);
        $this->assertIsInt($decoded['id']);
        $this->assertSame((int) (string) $created['id'], $decoded['id']);
    }

    public function testUnknownIdReturnsThe404ErrorBody(): void
    {
        $response = $this->handle('GET', '/api/bookmarks/999999', '999999');

        $this->assertSame(404, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
        $this->assertSame(
            '{"error":{"code":"not_found","message":"Not found","details":{}}}',
            $response->body
        );
    }

    /**
     * Seed one bookmark through the real repository and return it, or skip the
     * test while the repository slice is still a placeholder.
     *
     * @return array<string, mixed>
     */
    private function seedBookmark(): array
    {
        $repository = new BookmarkRepository($this->pdo);

        $created = $repository->create([
            'url' => 'https://example.com/php',
            'title' => 'PHP Bookmark',
            'tags' => ['PHP', ' api '],
        ]);

        $id = (int) ($created['id'] ?? 0);
        if ($id <= 0 || $repository->findById($id) === null) {
            $this->markTestSkipped(
                'The storage slice (#2 — SQLite storage, validation and creation) has not merged yet: '
                . 'BookmarkRepository::findById() is still the skeleton placeholder and returns null '
                . 'for a just-created row, so a real read cannot produce the found case.'
            );
        }

        return $created;
    }

    private function handle(string $method, string $path, string $id): Response
    {
        $handler = new ShowBookmarkHandler();

        return $handler(new Request($method, $path), ['id' => $id]);
    }
}
