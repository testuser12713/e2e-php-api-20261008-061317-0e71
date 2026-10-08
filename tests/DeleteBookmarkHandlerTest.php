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
 */
final class DeleteBookmarkHandlerTest extends TestCase
{
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
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function seedBookmark(array $data): array
    {
        $repository = new BookmarkRepository(Database::connection());

        return $repository->create($data);
    }

    private function invoke(int $id): Response
    {
        $request = new Request('DELETE', '/api/bookmarks/' . $id);

        return (new DeleteBookmarkHandler())($request, ['id' => (string) $id]);
    }

    public function testDeleteRemovesBookmarkAndAnswers204WithEmptyBody(): void
    {
        $created = $this->seedBookmark([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['one'],
        ]);
        $id = (int) $created['id'];

        $response = $this->invoke($id);

        $this->assertSame(204, $response->status);
        $this->assertSame('', $response->body);

        $repository = new BookmarkRepository(Database::connection());
        $this->assertNull($repository->findById($id));
        $this->assertSame([], $repository->findAll(null));
    }

    public function testSecondDeleteOfTheSameIdAnswers404(): void
    {
        $created = $this->seedBookmark([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => [],
        ]);
        $id = (int) $created['id'];

        $this->assertSame(204, $this->invoke($id)->status);

        $response = $this->invoke($id);

        $this->assertSame(404, $response->status);
        $this->assertSame(
            '{"error":{"code":"not_found","message":"Bookmark not found","details":{}}}',
            $response->body
        );
    }

    public function testUnknownIdAnswers404WithContractBody(): void
    {
        $response = $this->invoke(999999);

        $this->assertSame(404, $response->status);
        $this->assertSame(
            '{"error":{"code":"not_found","message":"Bookmark not found","details":{}}}',
            $response->body
        );

        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded);
        $this->assertSame('not_found', $decoded['error']['code']);
        $this->assertSame('Bookmark not found', $decoded['error']['message']);
        $this->assertSame([], $decoded['error']['details']);
    }
}
