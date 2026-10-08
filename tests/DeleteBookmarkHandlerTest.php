<?php

declare(strict_types=1);

namespace App\Tests;

use App\Handler\DeleteBookmarkHandler;
use App\Http\Request;
use App\Storage\BookmarkRepository;
use App\Storage\Database;
use PHPUnit\Framework\TestCase;

/**
 * Covers DELETE /api/bookmarks/{id} (AC-07).
 *
 * The storage slice ("Implement SQLite storage, bookmark validation and
 * creation") is still unmerged at the time of writing: Database::connection()
 * refuses and BookmarkRepository::create()/delete() are placeholders. The
 * assertions that need a real row are gated behind a probe that goes live by
 * itself the moment that slice lands, so this test is SKIPPED - never asserted
 * green against the placeholder's answer.
 */
final class DeleteBookmarkHandlerTest extends TestCase
{
    private string $dbPath = '';

    private string|false $previousDbPath = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousDbPath = getenv('DB_PATH');

        $this->dbPath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'bookmarks_delete_' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->removeDatabaseFiles($this->dbPath);

        putenv('DB_PATH=' . $this->dbPath);
        $_ENV['DB_PATH'] = $this->dbPath;
        $_SERVER['DB_PATH'] = $this->dbPath;
    }

    protected function tearDown(): void
    {
        if ($this->dbPath !== '') {
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

    /**
     * Probe: performs a genuine create/delete round trip through the storage
     * slice. False means the storage ticket is unmerged and the write
     * assertions cannot run yet; true means the test runs for real.
     */
    private function storageIsLive(): bool
    {
        try {
            $repository = new BookmarkRepository(Database::connection());
            $probe = $repository->create([
                'url' => 'https://probe.example/readiness',
                'title' => 'Readiness probe',
                'tags' => [],
            ]);

            if (!is_array($probe) || !isset($probe['id'])) {
                return false;
            }

            return $repository->delete((int) $probe['id']);
        } catch (\Throwable) {
            return false;
        }
    }

    public function testDeletionRemovesBookmarkThenAnswersNotFound(): void
    {
        if (!$this->storageIsLive()) {
            $this->markTestSkipped(
                'SQLite storage slice is not merged yet (owner: "Implement SQLite storage, '
                . 'bookmark validation and creation"); deletion cannot be exercised until it lands.'
            );
        }

        $repository = new BookmarkRepository(Database::connection());
        $bookmark = $repository->create([
            'url' => 'https://example.com/delete-me',
            'title' => 'Delete me',
            'tags' => ['cleanup'],
        ]);
        $id = (int) $bookmark['id'];

        $handler = new DeleteBookmarkHandler();

        $response = $handler(new Request(), ['id' => (string) $id]);

        $this->assertSame(204, $response->status);
        $this->assertSame('', $response->body);
        $this->assertNull($repository->findById($id));

        $second = $handler(new Request(), ['id' => (string) $id]);
        $this->assertSame(404, $second->status);
        $this->assertSame(
            '{"error":{"code":"not_found","message":"Bookmark not found","details":{}}}',
            $second->body
        );

        $unknown = $handler(new Request(), ['id' => '999999']);
        $this->assertSame(404, $unknown->status);
        $this->assertSame(
            '{"error":{"code":"not_found","message":"Bookmark not found","details":{}}}',
            $unknown->body
        );
    }
}
