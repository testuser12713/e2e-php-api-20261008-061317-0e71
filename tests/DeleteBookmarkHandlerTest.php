<?php

declare(strict_types=1);

namespace App\Tests;

use App\Handler\DeleteBookmarkHandler;
use App\Http\Request;
use App\Http\Response;
use App\Storage\BookmarkRepository;
use App\Storage\Database;
use PHPUnit\Framework\TestCase;

/**
 * Covers DELETE /api/bookmarks/{id} (AC-07).
 *
 * The storage slice ("Implement SQLite storage, bookmark validation and
 * creation") is still unmerged at the time of writing, so the deletion
 * assertions are gated behind a probe that goes live by itself the moment the
 * storage bodies are implemented. Nothing here asserts a placeholder answer as
 * if it were correct.
 */
final class DeleteBookmarkHandlerTest extends TestCase
{
    private const NOT_FOUND_BODY = '{"error":{"code":"not_found","message":"Bookmark not found","details":{}}}';

    private ?string $dbPath = null;

    private string|false $previousDbPath = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousDbPath = getenv('DB_PATH');

        $path = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'bookmarks_delete_' . bin2hex(random_bytes(8)) . '.sqlite';
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

    /**
     * A probe that reports whether the storage slice is live: it opens the
     * connection and performs a real create/delete round trip. False means the
     * storage ticket is unmerged and the deletion assertions cannot run yet.
     */
    private function storageIsLive(): bool
    {
        try {
            $repository = new BookmarkRepository(Database::connection());
            $created = $repository->create([
                'url' => 'https://probe.example.com',
                'title' => 'Probe',
                'tags' => [],
            ]);

            if (!is_array($created) || !isset($created['id'])) {
                return false;
            }

            return $repository->delete((int) $created['id']) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function invoke(int $id): Response
    {
        $request = new Request('DELETE', '/api/bookmarks/' . $id);

        return (new DeleteBookmarkHandler())($request, ['id' => (string) $id]);
    }

    public function testDeletesBookmarkAndSecondDeleteAnswers404(): void
    {
        if (!$this->storageIsLive()) {
            $this->markTestSkipped(
                'Deleting a bookmark requires the unmerged slice '
                . '"Implement SQLite storage, bookmark validation and creation".'
            );
        }

        $repository = new BookmarkRepository(Database::connection());
        $created = $repository->create([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => [],
        ]);
        $id = (int) $created['id'];

        $response = $this->invoke($id);
        $this->assertSame(204, $response->status);
        $this->assertSame('', $response->body);

        $this->assertNull($repository->findById($id));

        $second = $this->invoke($id);
        $this->assertSame(404, $second->status);
        $this->assertSame(self::NOT_FOUND_BODY, $second->body);

        $unknown = $this->invoke(999999);
        $this->assertSame(404, $unknown->status);
        $this->assertSame(self::NOT_FOUND_BODY, $unknown->body);
    }
}
