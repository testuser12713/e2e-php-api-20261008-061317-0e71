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
 * Covers GET /api/bookmarks (AC-04): newest-first listing and the optional
 * exact, case-insensitive tag filter.
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
     * Seed the two bookmarks used by the listing assertions: a newer `php`
     * bookmark and an older `javascript` one, with distinct created_at values.
     *
     * @return array{php: array<string, mixed>, js: array<string, mixed>}
     */
    private function seedBookmarks(): array
    {
        $repository = new BookmarkRepository(Database::connection());

        $php = $repository->create([
            'url' => 'https://php.example',
            'title' => 'PHP',
            'tags' => ['php'],
        ]);

        sleep(1);

        $js = $repository->create([
            'url' => 'https://js.example',
            'title' => 'JavaScript',
            'tags' => ['javascript'],
        ]);

        return ['php' => $php, 'js' => $js];
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return list<array<string, mixed>>
     */
    private function invoke(array $query = []): array
    {
        $request = new Request('GET', '/api/bookmarks', '', $query);
        $response = (new ListBookmarksHandler())($request, []);

        $this->assertSame(200, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);

        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    public function testListsAllBookmarksNewestFirst(): void
    {
        $seeded = $this->seedBookmarks();

        $bookmarks = $this->invoke();

        $this->assertCount(2, $bookmarks);
        $this->assertSame($seeded['js']['id'], $bookmarks[0]['id']);
        $this->assertSame($seeded['php']['id'], $bookmarks[1]['id']);
    }

    public function testTagFilterMatchesExactlyAndCaseInsensitively(): void
    {
        $seeded = $this->seedBookmarks();

        $bookmarks = $this->invoke(['tag' => 'PHP']);

        $this->assertCount(1, $bookmarks);
        $this->assertSame($seeded['php']['id'], $bookmarks[0]['id']);
    }

    public function testTagFilterDoesNotMatchPartially(): void
    {
        $this->seedBookmarks();

        $this->assertSame([], $this->invoke(['tag' => 'ph']));
    }

    public function testAbsentOrEmptyTagReturnsEverything(): void
    {
        $this->seedBookmarks();

        $this->assertCount(2, $this->invoke());
        $this->assertCount(2, $this->invoke(['tag' => '']));
    }
}
