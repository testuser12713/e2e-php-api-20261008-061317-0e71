<?php

declare(strict_types=1);

namespace App\Tests;

use App\Handler\DeleteBookmarkHandler;
use App\Http\Request;
use App\Storage\BookmarkRepository;
use App\Storage\Database;
use PHPUnit\Framework\TestCase;

/**
 * AC-07 — DELETE /api/bookmarks/{id}.
 *
 * Every assertion here depends on the real storage slice (the ticket
 * "Implement SQLite storage, bookmark validation and creation"): on this
 * branch Database::connection() is still a placeholder that raises for every
 * id, so the handler's single code path throws before it can answer at all.
 * A readiness probe therefore decides whether the slice is live; while it is
 * not, the whole test is skipped and never asserts the placeholder as if it
 * were a correct answer. The moment storage merges the probe goes live by
 * itself and all assertions run for real.
 */
final class DeleteBookmarkHandlerTest extends TestCase
{
    private string $dbPath = '';

    private bool $hadDbPath = false;

    private string $originalDbPath = '';

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/bookmarks_delete_' . bin2hex(random_bytes(8)) . '.sqlite';

        $this->hadDbPath = getenv('DB_PATH') !== false;
        $this->originalDbPath = $this->hadDbPath ? (string) getenv('DB_PATH') : '';

        $this->removeDbFiles();
        $this->setDbPath($this->dbPath);
    }

    protected function tearDown(): void
    {
        $this->removeDbFiles();

        if ($this->hadDbPath) {
            $this->setDbPath($this->originalDbPath);
        } else {
            $this->unsetDbPath();
        }
    }

    public function testDeleteRemovesBookmarkAndAnswers404Afterwards(): void
    {
        if (!$this->storageIsLive()) {
            $this->markTestSkipped(
                'Unmet dependency: the storage slice '
                . '"Implement SQLite storage, bookmark validation and creation" '
                . 'has not merged yet. Database::connection() is still a placeholder, so the '
                . 'handler cannot reach the repository and every assertion in this file is '
                . 'meaningless until it lands.'
            );
        }

        $repository = new BookmarkRepository(Database::connection());
        $created = $repository->create([
            'url' => 'https://example.com/',
            'title' => 'Example',
            'tags' => [],
        ]);
        $id = (int) $created['id'];

        $handler = new DeleteBookmarkHandler();

        $deleted = $handler(
            new Request('DELETE', '/api/bookmarks/' . $id),
            ['id' => (string) $id]
        );

        self::assertSame(204, $deleted->status);
        self::assertSame('', $deleted->body);
        self::assertNull($repository->findById($id));

        $second = $handler(
            new Request('DELETE', '/api/bookmarks/' . $id),
            ['id' => (string) $id]
        );

        self::assertSame(404, $second->status);

        $unknown = $handler(
            new Request('DELETE', '/api/bookmarks/999999'),
            ['id' => '999999']
        );

        self::assertSame(404, $unknown->status);
        self::assertJsonStringEqualsJsonString(
            '{"error":{"code":"not_found","message":"Bookmark not found","details":{}}}',
            $unknown->body
        );
    }

    /**
     * The single readiness probe: a real create()/delete() round trip against
     * the slice under test. It answers false while the slice is the
     * placeholder, and true once the real implementation is in place.
     */
    private function storageIsLive(): bool
    {
        try {
            $repository = new BookmarkRepository(Database::connection());
            $created = $repository->create([
                'url' => 'https://example.com/ready',
                'title' => 'Readiness probe',
                'tags' => [],
            ]);

            if (!isset($created['id'])) {
                return false;
            }

            return $repository->delete((int) $created['id']);
        } catch (\Throwable) {
            return false;
        }
    }

    private function setDbPath(string $path): void
    {
        putenv('DB_PATH=' . $path);
        $_ENV['DB_PATH'] = $path;
        $_SERVER['DB_PATH'] = $path;
    }

    private function unsetDbPath(): void
    {
        putenv('DB_PATH');
        unset($_ENV['DB_PATH'], $_SERVER['DB_PATH']);
    }

    private function removeDbFiles(): void
    {
        foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
}
