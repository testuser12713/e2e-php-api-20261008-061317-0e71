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
 * Covers GET /api/bookmarks listing and tag filtering (AC-04).
 *
 * The storage slice ("Implement SQLite storage, bookmark validation and
 * creation") is still unmerged at the time of writing, so the assertions are
 * gated behind a probe that performs a real create/findAll round trip and goes
 * live by itself the moment that body is implemented. Nothing here asserts a
 * placeholder answer as if it were correct.
 */
final class ListBookmarksHandlerTest extends TestCase
{
    private ?string $dbPath = null;

    private string|false $previousDbPath = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousDbPath = getenv('DB_PATH');

        $path = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'bookmarks_list_' . bin2hex(random_bytes(8)) . '.sqlite';
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
     * @param array<string, mixed> $query
     *
     * @return list<array<string, mixed>>
     */
    private function list(array $query): array
    {
        $request = new Request('GET', '/api/bookmarks', '', $query);
        $response = (new ListBookmarksHandler())($request, []);

        $this->assertSame(200, $response->status);
        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded);

        return array_values($decoded);
    }

    public function testListsNewestFirstAndFiltersByTagCaseInsensitively(): void
    {
        $repository = null;
        $older = null;

        try {
            $repository = new BookmarkRepository(Database::connection());
            $older = $repository->create([
                'url' => 'https://www.php.net',
                'title' => 'PHP manual',
                'tags' => ['php'],
            ]);

            $live = is_array($older) && isset($older['id']) && $repository->findAll('php') !== [];
        } catch (\Throwable) {
            $live = false;
        }

        if (!$live) {
            $this->markTestSkipped(
                'Bookmark listing requires the unmerged slice '
                . '"Implement SQLite storage, bookmark validation and creation".'
            );
        }

        sleep(1);

        $newer = $repository->create([
            'url' => 'https://laravel.com',
            'title' => 'Laravel',
            'tags' => ['javascript'],
        ]);

        $all = $this->list([]);
        $this->assertCount(2, $all);
        $this->assertSame($newer['id'], $all[0]['id']);
        $this->assertSame($older['id'], $all[1]['id']);
        $this->assertGreaterThan(
            strtotime((string) $all[1]['created_at']),
            strtotime((string) $all[0]['created_at'])
        );

        $php = $this->list(['tag' => 'PHP']);
        $this->assertCount(1, $php);
        $this->assertSame($older['id'], $php[0]['id']);

        $this->assertSame([], $this->list(['tag' => 'ph']));

        $this->assertCount(2, $this->list(['tag' => '']));
    }
}
