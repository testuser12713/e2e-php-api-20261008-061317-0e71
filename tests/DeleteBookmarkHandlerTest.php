<?php

declare(strict_types=1);

namespace App\Tests;

use App\Handler\DeleteBookmarkHandler;
use App\Http\Request;
use App\Http\Response;
use App\Storage\BookmarkRepository;
use App\Storage\Database;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Covers AC-07: DELETE /api/bookmarks/{id}.
 *
 * The sibling storage slice is still a placeholder on this branch
 * (Database::connection() raises "storage not implemented"), so the
 * assertions that need a real write are skipped until that slice lands —
 * never asserted against the placeholder as if it were correct behaviour.
 * The guard removes itself: the moment storage works, the tests run.
 */
final class DeleteBookmarkHandlerTest extends TestCase
{
    private string $dbPath;

    private bool $storageReady = false;

    private int $seededId = 0;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/bookmarks_delete_test_' . bin2hex(random_bytes(8)) . '.sqlite';
        putenv('DB_PATH=' . $this->dbPath);
        $_ENV['DB_PATH'] = $this->dbPath;
        $_SERVER['DB_PATH'] = $this->dbPath;

        if (!$this->storageIsImplemented()) {
            return;
        }

        $this->storageReady = true;

        $bookmark = (new BookmarkRepository(Database::connection()))->create([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['php'],
        ]);
        $this->seededId = (int) ($bookmark['id'] ?? 0);
    }

    protected function tearDown(): void
    {
        putenv('DB_PATH');
        unset($_ENV['DB_PATH'], $_SERVER['DB_PATH']);

        foreach (['', '-wal', '-shm'] as $suffix) {
            $file = $this->dbPath . $suffix;
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * True once the storage slice can open a real connection. The placeholder
     * raises a specific RuntimeException; anything else is a genuine defect.
     */
    private function storageIsImplemented(): bool
    {
        try {
            Database::connection();

            return true;
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'storage not implemented') {
                return false;
            }

            throw $exception;
        }
    }

    private function requireStorage(): void
    {
        if ($this->storageReady) {
            return;
        }

        $this->markTestSkipped(
            'storage slice "Implement SQLite storage, bookmark validation and creation" is still a '
            . 'placeholder (Database::connection() raises "storage not implemented"); a real delete '
            . 'cannot be exercised until it lands.'
        );
    }

    private function delete(int $id): Response
    {
        $handler = new DeleteBookmarkHandler();

        return $handler(new Request('DELETE', '/api/bookmarks/' . $id), ['id' => (string) $id]);
    }

    public function testDeleteAnswers204WithEmptyBodyAndRemovesTheBookmark(): void
    {
        $this->requireStorage();
        $this->assertGreaterThan(0, $this->seededId, 'the seeded bookmark must carry an id');

        $response = $this->delete($this->seededId);

        $this->assertSame(204, $response->status);
        $this->assertSame('', $response->body);

        $remaining = (new BookmarkRepository(Database::connection()))->findById($this->seededId);
        $this->assertNull($remaining, 'the deleted bookmark must be gone');
    }

    public function testSecondDeleteOfTheSameIdAnswers404(): void
    {
        $this->requireStorage();

        $this->assertSame(204, $this->delete($this->seededId)->status);
        $this->assertSame(404, $this->delete($this->seededId)->status);
    }

    public function testUnknownIdAnswers404WithTheContractBody(): void
    {
        $this->requireStorage();

        $response = $this->delete(999999);

        $this->assertSame(404, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
        $this->assertSame(
            '{"error":{"code":"not_found","message":"Not Found","details":{}}}',
            $response->body
        );
    }
}
