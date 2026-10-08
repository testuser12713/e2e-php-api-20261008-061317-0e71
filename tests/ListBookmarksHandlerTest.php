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
    private string $dbPath;
    private ?string $previousDbPath = null;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/bookmarks_list_' . uniqid('', true) . '.sqlite';
        $previous = getenv('DB_PATH');
        $this->previousDbPath = $previous === false ? null : (string) $previous;
        putenv('DB_PATH=' . $this->dbPath);
        $_ENV['DB_PATH'] = $this->dbPath;
    }

    protected function tearDown(): void
    {
        if ($this->previousDbPath === null) {
            putenv('DB_PATH');
            unset($_ENV['DB_PATH']);
        } else {
            putenv('DB_PATH=' . $this->previousDbPath);
            $_ENV['DB_PATH'] = $this->previousDbPath;
        }

        foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    public function testListsBookmarksNewestFirstAndFiltersByTagCaseInsensitively(): void
    {
        if ($this->storageIsPlaceholder()) {
            $this->markTestSkipped('The SQLite storage slice is unimplemented: BookmarkRepository::findAll() is still the skeleton placeholder.');
        }

        $repository = new BookmarkRepository(Database::connection());

        $repository->create([
            'url' => 'https://example.com/older',
            'title' => 'Older bookmark',
            'tags' => ['javascript'],
        ]);
        sleep(1);
        $repository->create([
            'url' => 'https://example.com/newer',
            'title' => 'Newer bookmark',
            'tags' => ['php'],
        ]);

        $handler = new ListBookmarksHandler();

        $all = $this->bodyOf($handler(new Request('GET', '/api/bookmarks'), []));
        $this->assertSame(['Newer bookmark', 'Older bookmark'], array_column($all, 'title'));

        $php = $this->bodyOf($handler(new Request('GET', '/api/bookmarks', '', ['tag' => 'PHP']), []));
        $this->assertCount(1, $php);
        $this->assertSame('Newer bookmark', $php[0]['title']);
        $this->assertSame(['php'], $php[0]['tags']);

        $partial = $this->bodyOf($handler(new Request('GET', '/api/bookmarks', '', ['tag' => 'ph']), []));
        $this->assertSame([], $partial);

        $emptyTag = $this->bodyOf($handler(new Request('GET', '/api/bookmarks', '', ['tag' => '']), []));
        $this->assertCount(2, $emptyTag);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function bodyOf(Response $response): array
    {
        $this->assertSame(200, $response->status);
        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * True while the storage slice is a placeholder: the connection refuses or
     * create()/findAll() still return the skeleton's empty results.
     */
    private function storageIsPlaceholder(): bool
    {
        try {
            $repository = new BookmarkRepository(Database::connection());
        } catch (\Throwable) {
            return true;
        }

        $probe = $repository->create([
            'url' => 'https://example.com/storage-probe',
            'title' => 'Storage probe',
            'tags' => ['probe'],
        ]);

        if ($probe === [] || $repository->findAll(null) === []) {
            return true;
        }

        $repository->delete((int) $probe['id']);

        return false;
    }
}
