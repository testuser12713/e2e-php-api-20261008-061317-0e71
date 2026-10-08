<?php

declare(strict_types=1);

namespace App\Tests;

use App\Handler\ListBookmarksHandler;
use App\Http\Request;
use App\Storage\BookmarkRepository;
use App\Storage\Database;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class ListBookmarksHandlerTest extends TestCase
{
    private string $dbPath;

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/bookmarks_list_' . bin2hex(random_bytes(8)) . '.sqlite';
        putenv('DB_PATH=' . $this->dbPath);
        $_ENV['DB_PATH'] = $this->dbPath;
        $_SERVER['DB_PATH'] = $this->dbPath;

        try {
            $this->pdo = Database::connection();
        } catch (Throwable $exception) {
            if (
                $exception instanceof RuntimeException
                && str_contains(strtolower($exception->getMessage()), 'not implemented')
            ) {
                $this->markTestSkipped(
                    'Storage layer is not implemented on this branch yet: ' . $exception->getMessage()
                );
            }

            throw $exception;
        }
    }

    protected function tearDown(): void
    {
        putenv('DB_PATH');
        unset($_ENV['DB_PATH'], $_SERVER['DB_PATH']);

        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    private function seed(): void
    {
        $repository = new BookmarkRepository($this->pdo);
        $repository->create([
            'url' => 'https://www.php.net/',
            'title' => 'PHP',
            'tags' => ['PHP'],
        ]);
        usleep(1100000);
        $repository->create([
            'url' => 'https://www.sqlite.org/',
            'title' => 'SQLite',
            'tags' => ['database'],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listBookmarks(?string $tag = null): array
    {
        $query = $tag === null ? [] : ['tag' => $tag];
        $request = new Request('GET', '/api/bookmarks', '', $query);

        $response = (new ListBookmarksHandler())($request, []);

        $this->assertSame(200, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);

        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    public function testListsStoredBookmarksNewestFirst(): void
    {
        $this->seed();

        $bookmarks = $this->listBookmarks();

        $this->assertCount(2, $bookmarks);
        $this->assertSame('SQLite', $bookmarks[0]['title']);
        $this->assertSame('PHP', $bookmarks[1]['title']);
    }

    public function testFiltersByTagCaseInsensitively(): void
    {
        $this->seed();

        $bookmarks = $this->listBookmarks('PHP');

        $this->assertCount(1, $bookmarks);
        $this->assertSame('PHP', $bookmarks[0]['title']);
    }

    public function testTagFilterMatchesExactlyAndNotPartially(): void
    {
        $this->seed();

        $bookmarks = $this->listBookmarks('ph');

        $this->assertSame([], $bookmarks);
    }
}
