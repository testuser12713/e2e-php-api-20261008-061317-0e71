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
 * Verifies the DELETE /api/bookmarks/{id} handler contract (AC-07).
 *
 * The handler has exactly one code path: it deletes through
 * BookmarkRepository::delete() on a Database::connection(). While the sibling
 * storage slice is still a placeholder on this branch that connection raises
 * RuntimeException('storage not implemented'), so the assertions that need a
 * live storage connection are marked skipped and disappear by themselves once
 * storage lands.
 */
final class DeleteBookmarkHandlerTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbPath = sys_get_temp_dir() . '/bookmarks_delete_' . bin2hex(random_bytes(8)) . '.sqlite';
        putenv('DB_PATH=' . $this->dbPath);
        $_ENV['DB_PATH'] = $this->dbPath;
        $_SERVER['DB_PATH'] = $this->dbPath;
    }

    protected function tearDown(): void
    {
        putenv('DB_PATH');
        unset($_ENV['DB_PATH'], $_SERVER['DB_PATH']);

        if (isset($this->dbPath) && is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }

        parent::tearDown();
    }

    /**
     * Skip when the storage slice is still the placeholder that refuses to open
     * a connection. Owned by "Implement SQLite storage, bookmark validation and
     * creation" (#2); the guard removes itself once that slice lands.
     */
    private function skipIfStorageIsPlaceholder(): void
    {
        try {
            Database::connection();
        } catch (RuntimeException $exception) {
            if (str_contains($exception->getMessage(), 'storage not implemented')) {
                $this->markTestSkipped(
                    'Bookmark storage is still a placeholder on this branch '
                    . '("storage not implemented"); the delete path needs the '
                    . '"Implement SQLite storage, bookmark validation and creation" '
                    . 'slice (#2) before it can be exercised.'
                );
            }

            throw $exception;
        }
    }

    private function invoke(string $id): Response
    {
        return (new DeleteBookmarkHandler())(
            new Request('DELETE', '/api/bookmarks/' . $id),
            ['id' => $id]
        );
    }

    public function testDeletesBookmarkAndAnswers204WithEmptyBody(): void
    {
        $this->skipIfStorageIsPlaceholder();

        $repository = new BookmarkRepository(Database::connection());
        $bookmark = $repository->create([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['php'],
        ]);
        $id = (int) $bookmark['id'];

        $response = $this->invoke((string) $id);

        $this->assertSame(204, $response->status);
        $this->assertSame('', $response->body);
        $this->assertNull($repository->findById($id));
    }

    public function testSecondDeleteOfSameIdAnswers404(): void
    {
        $this->skipIfStorageIsPlaceholder();

        $repository = new BookmarkRepository(Database::connection());
        $bookmark = $repository->create([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => [],
        ]);
        $id = (string) (int) $bookmark['id'];

        $first = $this->invoke($id);
        $this->assertSame(204, $first->status);
        $this->assertSame('', $first->body);

        $second = $this->invoke($id);
        $this->assertSame(404, $second->status);
        $this->assertSame('application/json; charset=utf-8', $second->headers['Content-Type']);
    }

    public function testUnknownIdAnswers404WithJsonErrorBody(): void
    {
        $this->skipIfStorageIsPlaceholder();

        $response = $this->invoke('999999');

        $this->assertSame(404, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
        $this->assertSame(
            '{"error":{"code":"not_found","message":"Not Found","details":{}}}',
            $response->body
        );
    }
}
