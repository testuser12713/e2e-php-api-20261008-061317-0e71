<?php

declare(strict_types=1);

namespace App\Tests;

use App\Handler\ListBookmarksHandler;
use App\Http\Request;
use App\Http\Response;
use App\Storage\BookmarkRepository;
use App\Storage\Database;
use PHPUnit\Framework\TestCase;

/**
 * Covers GET /api/bookmarks and its `tag` filter (AC-06).
 */
final class ListBookmarksHandlerTest extends TestCase
{
    private string $dbPath;

    private string|false $previousDbPath = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousDbPath = getenv('DB_PATH');

        $this->dbPath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'bookmarks_list_' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->removeDatabaseFiles($this->dbPath);

        putenv('DB_PATH=' . $this->dbPath);
        $_ENV['DB_PATH'] = $this->dbPath;
        $_SERVER['DB_PATH'] = $this->dbPath;
    }

    protected function tearDown(): void
    {
        $this->removeDatabaseFiles($this->dbPath);

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
    private function seed(array $data): array
    {
        return (new BookmarkRepository(Database::connection()))->create($data);
    }

    /**
     * Backdate a stored bookmark so the ordering assertion has two clearly
     * distinct created_at values instead of two timestamps from the same second.
     */
    private function backdate(int $id, string $createdAt): void
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare('UPDATE bookmarks SET created_at = :created_at WHERE id = :id');
        $statement->execute([':created_at' => $createdAt, ':id' => $id]);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function invoke(array $query = []): Response
    {
        $request = new Request('GET', '/api/bookmarks', '', $query);

        return (new ListBookmarksHandler())($request, []);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Response $response): array
    {
        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    public function testListsEveryBookmarkNewestFirst(): void
    {
        $php = $this->seed([
            'url' => 'https://example.com/php',
            'title' => 'PHP Bookmark',
            'tags' => ['php'],
        ]);
        $this->backdate((int) $php['id'], '2020-01-01T00:00:00Z');

        $js = $this->seed([
            'url' => 'https://example.com/js',
            'title' => 'JS Bookmark',
            'tags' => ['javascript'],
        ]);

        $response = $this->invoke();

        $this->assertSame(200, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);

        $bookmarks = $this->body($response);
        $this->assertCount(2, $bookmarks);
        $this->assertSame([$js['id'], $php['id']], array_column($bookmarks, 'id'));
        $this->assertSame($js['created_at'], $bookmarks[0]['created_at']);
        $this->assertSame('2020-01-01T00:00:00Z', $bookmarks[1]['created_at']);
    }

    public function testTagFilterMatchesCaseInsensitivelyAndExactly(): void
    {
        $php = $this->seed([
            'url' => 'https://example.com/php',
            'title' => 'PHP Bookmark',
            'tags' => ['php'],
        ]);
        $this->backdate((int) $php['id'], '2020-01-01T00:00:00Z');

        $this->seed([
            'url' => 'https://example.com/js',
            'title' => 'JS Bookmark',
            'tags' => ['javascript'],
        ]);

        $response = $this->invoke(['tag' => 'PHP']);

        $this->assertSame(200, $response->status);
        $bookmarks = $this->body($response);
        $this->assertCount(1, $bookmarks);
        $this->assertSame($php['id'], $bookmarks[0]['id']);
        $this->assertSame(['php'], $bookmarks[0]['tags']);
    }

    public function testPartialTagDoesNotMatch(): void
    {
        $this->seed([
            'url' => 'https://example.com/php',
            'title' => 'PHP Bookmark',
            'tags' => ['php'],
        ]);

        $response = $this->invoke(['tag' => 'ph']);

        $this->assertSame(200, $response->status);
        $this->assertSame([], $this->body($response));
    }

    public function testEmptyTagReturnsEverything(): void
    {
        $php = $this->seed([
            'url' => 'https://example.com/php',
            'title' => 'PHP Bookmark',
            'tags' => ['php'],
        ]);
        $this->backdate((int) $php['id'], '2020-01-01T00:00:00Z');

        $js = $this->seed([
            'url' => 'https://example.com/js',
            'title' => 'JS Bookmark',
            'tags' => ['javascript'],
        ]);

        $response = $this->invoke(['tag' => '']);

        $this->assertSame(200, $response->status);
        $bookmarks = $this->body($response);
        $this->assertCount(2, $bookmarks);
        $this->assertSame([$js['id'], $php['id']], array_column($bookmarks, 'id'));
    }

    public function testNoMatchesReturnsEmptyList(): void
    {
        $this->seed([
            'url' => 'https://example.com/js',
            'title' => 'JS Bookmark',
            'tags' => ['javascript'],
        ]);

        $response = $this->invoke(['tag' => 'python']);

        $this->assertSame(200, $response->status);
        $this->assertSame([], $this->body($response));
    }
}
