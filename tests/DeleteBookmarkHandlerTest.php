<?php

declare(strict_types=1);

namespace App\Tests;

use App\Handler\DeleteBookmarkHandler;
use App\Http\Request;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the DELETE /api/bookmarks/{id} handler contract (AC-07) against a
 * temporary SQLite database.
 *
 * The concrete BookmarkRepository is implemented by a separate storage ticket,
 * so the handler is driven with a deletion function bound to a real, temporary
 * SQLite file. The file location follows DB_PATH, which is reset here.
 */
final class DeleteBookmarkHandlerTest extends TestCase
{
    private string $dbPath;

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/bookmarks_delete_' . bin2hex(random_bytes(8)) . '.sqlite';
        putenv('DB_PATH=' . $this->dbPath);

        $this->pdo = new PDO('sqlite:' . $this->dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE bookmarks ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
            . 'url TEXT NOT NULL,'
            . 'title TEXT NOT NULL,'
            . 'tags TEXT NOT NULL DEFAULT \'[]\','
            . 'created_at TEXT NOT NULL,'
            . 'updated_at TEXT NOT NULL'
            . ')'
        );
        $this->pdo->exec(
            "INSERT INTO bookmarks (url, title, tags, created_at, updated_at) "
            . "VALUES ('https://example.com', 'Example', '[]', '2024-01-01T00:00:00Z', '2024-01-01T00:00:00Z')"
        );
    }

    protected function tearDown(): void
    {
        putenv('DB_PATH');
        unset($this->pdo);
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    /**
     * Build a handler whose deletion runs against the temporary SQLite file.
     */
    private function handler(): DeleteBookmarkHandler
    {
        $pdo = $this->pdo;

        return new DeleteBookmarkHandler(
            static function (int $id) use ($pdo): bool {
                $statement = $pdo->prepare('DELETE FROM bookmarks WHERE id = :id');
                $statement->execute([':id' => $id]);

                return $statement->rowCount() > 0;
            }
        );
    }

    public function testDeletesBookmarkAndAnswers204WithEmptyBody(): void
    {
        $response = ($this->handler())(
            new Request('DELETE', '/api/bookmarks/1'),
            ['id' => '1']
        );

        $this->assertSame(204, $response->status);
        $this->assertSame('', $response->body);

        $remaining = $this->pdo->query('SELECT COUNT(*) FROM bookmarks')->fetchColumn();
        $this->assertSame(0, (int) $remaining);
    }

    public function testSecondDeleteOfSameIdAnswers404(): void
    {
        $handler = $this->handler();

        $first = $handler(new Request('DELETE', '/api/bookmarks/1'), ['id' => '1']);
        $this->assertSame(204, $first->status);

        $second = $handler(new Request('DELETE', '/api/bookmarks/1'), ['id' => '1']);
        $this->assertSame(404, $second->status);
        $this->assertSame('application/json; charset=utf-8', $second->headers['Content-Type']);
        $this->assertStringContainsString('"code":"not_found"', $second->body);
    }

    public function testUnknownIdAnswers404WithJsonErrorBody(): void
    {
        $response = ($this->handler())(
            new Request('DELETE', '/api/bookmarks/999'),
            ['id' => '999']
        );

        $this->assertSame(404, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
        $this->assertSame(
            '{"error":{"code":"not_found","message":"Not Found","details":{}}}',
            $response->body
        );
    }
}
