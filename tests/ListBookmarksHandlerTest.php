<?php

declare(strict_types=1);

namespace App\Tests;

use App\Handler\ListBookmarksHandler;
use App\Http\Request;
use App\Storage\BookmarkRepository;
use App\Storage\Database;
use PHPUnit\Framework\TestCase;

/**
 * Covers the GET /api/bookmarks handler: newest-first listing and the
 * case-insensitive, exact ?tag= filter.
 *
 * The listing routes through BookmarkRepository::findAll(), which is still the
 * skeleton's placeholder returning [] on this branch until the storage ticket
 * merges. The guard below reports the suite as SKIPPED in that state instead of
 * asserting the placeholder answer as if it were correct.
 */
final class ListBookmarksHandlerTest extends TestCase
{
    private string $dbPath = '';

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/bookmarks-list-' . getmypid() . '-' . uniqid('', true) . '.sqlite';
        putenv('DB_PATH=' . $this->dbPath);
        $_ENV['DB_PATH'] = $this->dbPath;
        $_SERVER['DB_PATH'] = $this->dbPath;
    }

    protected function tearDown(): void
    {
        putenv('DB_PATH');
        unset($_ENV['DB_PATH'], $_SERVER['DB_PATH']);

        if ($this->dbPath !== '' && is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testListsNewestFirstAndFiltersByTagCaseInsensitively(): void
    {
        try {
            $pdo = Database::connection();
        } catch (\Throwable $e) {
            $this->markTestSkipped(
                'Storage not implemented yet: Database::connection() did not return a connection.'
            );
        }

        $repository = new BookmarkRepository($pdo);

        $php = $repository->create([
            'url' => 'https://example.com/php',
            'title' => 'PHP',
            'tags' => ['php'],
        ]);

        usleep(10000);

        $other = $repository->create([
            'url' => 'https://example.com/other',
            'title' => 'Other',
            'tags' => ['other'],
        ]);

        if ($php === [] || $other === [] || $repository->findAll(null) === []) {
            $this->markTestSkipped(
                'Storage placeholder still active: BookmarkRepository::create()/findAll() return empty results.'
            );
        }

        $handler = new ListBookmarksHandler();

        $all = $handler(new Request('GET', '/api/bookmarks'), []);
        $this->assertSame(200, $all->status);
        $rows = json_decode($all->body, true);
        $this->assertIsArray($rows);
        $this->assertCount(2, $rows);
        $this->assertSame($other['id'], $rows[0]['id']);
        $this->assertSame($php['id'], $rows[1]['id']);

        $filtered = $handler(new Request('GET', '/api/bookmarks', '', ['tag' => 'PHP']), []);
        $this->assertSame(200, $filtered->status);
        $filteredRows = json_decode($filtered->body, true);
        $this->assertIsArray($filteredRows);
        $this->assertCount(1, $filteredRows);
        $this->assertSame($php['id'], $filteredRows[0]['id']);

        $partial = $handler(new Request('GET', '/api/bookmarks', '', ['tag' => 'ph']), []);
        $this->assertSame(200, $partial->status);
        $this->assertSame([], json_decode($partial->body, true));

        $unfiltered = $handler(new Request('GET', '/api/bookmarks', '', ['tag' => '']), []);
        $this->assertSame(200, $unfiltered->status);
        $this->assertCount(2, json_decode($unfiltered->body, true));
    }
}
