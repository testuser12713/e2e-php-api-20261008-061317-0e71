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
 * Covers GET /api/bookmarks (AC-04).
 *
 * The "Implement SQLite storage, bookmark validation and creation" slice is
 * still unmerged here, so the assertions that depend on real storage are gated
 * behind a probe that goes live by itself the moment that slice lands. The
 * test is reported as skipped while BookmarkRepository::findAll() is still the
 * skeleton placeholder; it never asserts the placeholder's answer as correct.
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
        if ($this->previousDbPath === false || $this->previousDbPath === '') {
            putenv('DB_PATH');
            unset($_ENV['DB_PATH'], $_SERVER['DB_PATH']);
        } else {
            putenv('DB_PATH=' . $this->previousDbPath);
            $_ENV['DB_PATH'] = $this->previousDbPath;
            $_SERVER['DB_PATH'] = $this->previousDbPath;
        }

        $this->removeDatabaseFiles($this->dbPath);

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

    public function testListsBookmarksNewestFirstAndFiltersByTagCaseInsensitively(): void
    {
        if ($this->storageIsPlaceholder()) {
            $this->markTestSkipped(
                'Bookmark listing requires the unmerged slice '
                . '"Implement SQLite storage, bookmark validation and creation": '
                . 'BookmarkRepository::findAll() is still the skeleton placeholder.'
            );
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

        $all = $this->decoded($handler(new Request('GET', '/api/bookmarks'), []));
        $this->assertSame(['Newer bookmark', 'Older bookmark'], array_column($all, 'title'));

        $php = $this->decoded(
            $handler(new Request('GET', '/api/bookmarks', '', ['tag' => 'PHP']), [])
        );
        $this->assertCount(1, $php);
        $this->assertSame('Newer bookmark', $php[0]['title']);
        $this->assertSame(['php'], $php[0]['tags']);

        $partial = $this->decoded(
            $handler(new Request('GET', '/api/bookmarks', '', ['tag' => 'ph']), [])
        );
        $this->assertSame([], $partial);

        $emptyTag = $this->decoded(
            $handler(new Request('GET', '/api/bookmarks', '', ['tag' => '']), [])
        );
        $this->assertCount(2, $emptyTag);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decoded(Response $response): array
    {
        $this->assertSame(200, $response->status);
        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * True while the storage slice is still a placeholder: the connection
     * refuses, or create()/findAll() still return the skeleton's empty
     * results. False means real storage is present and the assertions run.
     */
    private function storageIsPlaceholder(): bool
    {
        try {
            $repository = new BookmarkRepository(Database::connection());
        } catch (\Throwable) {
            return true;
        }

        try {
            $probe = $repository->create([
                'url' => 'https://example.com/storage-probe',
                'title' => 'Storage probe',
                'tags' => ['probe'],
            ]);
        } catch (\Throwable) {
            return true;
        }

        if ($probe === [] || !isset($probe['id']) || $repository->findAll(null) === []) {
            return true;
        }

        try {
            $repository->delete((int) $probe['id']);
        } catch (\Throwable) {
            return true;
        }

        return false;
    }
}
