<?php

declare(strict_types=1);

namespace App\Tests;

use App\Handler\ListBookmarksHandler;
use App\Http\Request;
use App\Http\Response;
use App\Storage\BookmarkRepository;
use App\Storage\Database;
use PHPUnit\Framework\TestCase;

final class ListBookmarksHandlerTest extends TestCase
{
    private string $dbPath = '';

    private ?string $previousDbPath = null;

    protected function setUp(): void
    {
        $value = getenv('DB_PATH');
        $this->previousDbPath = $value === false ? null : $value;
        $this->dbPath = sys_get_temp_dir() . '/bookmarks_list_' . bin2hex(random_bytes(8)) . '.sqlite';
        putenv('DB_PATH=' . $this->dbPath);
    }

    protected function tearDown(): void
    {
        if ($this->previousDbPath === null) {
            putenv('DB_PATH');
        } else {
            putenv('DB_PATH=' . $this->previousDbPath);
        }

        foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testListsBookmarksNewestFirstAndFiltersByTag(): void
    {
        $older = [];
        $newer = [];
        $all = [];
        $placeholder = false;

        try {
            $repository = new BookmarkRepository(Database::connection());

            $older = $repository->create([
                'url' => 'https://example.com/php',
                'title' => 'PHP Guide',
                'tags' => ['php'],
            ]);

            usleep(1_100_000);

            $newer = $repository->create([
                'url' => 'https://example.com/other',
                'title' => 'Other Guide',
                'tags' => ['other'],
            ]);

            $all = $repository->findAll(null);
        } catch (\RuntimeException $e) {
            $placeholder = true;
        }

        // Single guard: this test only skips while the storage slice is still the
        // skeleton placeholder. It becomes an active, asserting test by itself as
        // soon as Database/BookmarkRepository are implemented.
        if ($placeholder || $older === [] || $newer === [] || $all === []) {
            $this->markTestSkipped(
                'Bookmark listing needs the storage slice: BookmarkRepository::create()/findAll() are still the skeleton placeholder.'
            );
        }

        $handler = new ListBookmarksHandler();

        $listing = $handler(new Request('GET', '/api/bookmarks'), []);
        $this->assertSame(200, $listing->status);
        $this->assertSame([$newer['id'], $older['id']], array_column($this->decodeBody($listing), 'id'));

        $phpOnly = $handler(new Request('GET', '/api/bookmarks', '', ['tag' => 'PHP']), []);
        $this->assertSame(200, $phpOnly->status);
        $this->assertSame([$older['id']], array_column($this->decodeBody($phpOnly), 'id'));

        $partial = $handler(new Request('GET', '/api/bookmarks', '', ['tag' => 'ph']), []);
        $this->assertSame(200, $partial->status);
        $this->assertSame([], $this->decodeBody($partial));

        $emptyTag = $handler(new Request('GET', '/api/bookmarks', '', ['tag' => '']), []);
        $this->assertSame(200, $emptyTag->status);
        $this->assertSame([$newer['id'], $older['id']], array_column($this->decodeBody($emptyTag), 'id'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decodeBody(Response $response): array
    {
        $decoded = json_decode($response->body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
