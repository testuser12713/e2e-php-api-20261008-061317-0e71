<?php

declare(strict_types=1);

namespace App\Tests;

use App\Storage\BookmarkRepository;
use App\Storage\Database;
use PHPUnit\Framework\TestCase;

/**
 * Covers the storage layer (AC-01, AC-03, AC-10, AC-11): create, read, update,
 * delete, the exact case-insensitive tag filter, tag normalization on write and
 * read, and persistence across two repository instances on the same file.
 */
final class BookmarkRepositoryTest extends TestCase
{
    private ?string $dbPath = null;

    private string|false $previousDbPath = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousDbPath = getenv('DB_PATH');

        $path = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'bookmarks_repo_' . bin2hex(random_bytes(8)) . '.sqlite';
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

    private function repository(): BookmarkRepository
    {
        return new BookmarkRepository(Database::connection());
    }

    /**
     * @param array<string, mixed> $bookmark
     */
    private function assertBookmarkShape(array $bookmark): void
    {
        $keys = array_keys($bookmark);
        sort($keys);
        $this->assertSame(['created_at', 'id', 'tags', 'title', 'updated_at', 'url'], $keys);
        $this->assertIsInt($bookmark['id']);
        $this->assertIsString($bookmark['url']);
        $this->assertIsString($bookmark['title']);
        $this->assertIsArray($bookmark['tags']);
        $this->assertIsString($bookmark['created_at']);
        $this->assertIsString($bookmark['updated_at']);
    }

    public function testCreateStoresAndReturnsTheBookmark(): void
    {
        $created = $this->repository()->create([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['php', 'api'],
        ]);

        $this->assertBookmarkShape($created);
        $this->assertGreaterThan(0, $created['id']);
        $this->assertSame('https://example.com', $created['url']);
        $this->assertSame('Example', $created['title']);
        $this->assertSame(['php', 'api'], $created['tags']);
        $this->assertSame($created['created_at'], $created['updated_at']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            (string) $created['created_at']
        );
    }

    public function testFindByIdReturnsTheStoredBookmark(): void
    {
        $created = $this->repository()->create([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => [],
        ]);

        $found = $this->repository()->findById((int) $created['id']);

        $this->assertNotNull($found);
        $this->assertSame($created, $found);
    }

    public function testFindByIdReturnsNullForUnknownId(): void
    {
        $this->assertNull($this->repository()->findById(999999));
    }

    public function testTagsAreNormalizedOnWrite(): void
    {
        $created = $this->repository()->create([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => [' PHP ', 'php', 'Go', 'gO', '  '],
        ]);

        $this->assertSame(['php', 'go'], $created['tags']);
    }

    public function testTagsAreNormalizedOnRead(): void
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO bookmarks (url, title, tags, created_at, updated_at) '
            . 'VALUES (:url, :title, :tags, :created_at, :updated_at)'
        )->execute([
            'url' => 'https://example.com',
            'title' => 'Raw',
            'tags' => json_encode([' PHP ', 'php', 'Go']),
            'created_at' => '2024-01-01T00:00:00Z',
            'updated_at' => '2024-01-01T00:00:00Z',
        ]);

        $id = (int) $pdo->lastInsertId();
        $found = $this->repository()->findById($id);

        $this->assertNotNull($found);
        $this->assertSame(['php', 'go'], $found['tags']);
    }

    public function testFindAllReturnsNewestFirst(): void
    {
        $first = $this->repository()->create([
            'url' => 'https://one.example.com',
            'title' => 'One',
            'tags' => [],
        ]);
        sleep(1);
        $second = $this->repository()->create([
            'url' => 'https://two.example.com',
            'title' => 'Two',
            'tags' => [],
        ]);

        $all = $this->repository()->findAll(null);

        $this->assertCount(2, $all);
        $this->assertSame((int) $second['id'], $all[0]['id']);
        $this->assertSame((int) $first['id'], $all[1]['id']);
    }

    public function testFindAllFiltersByExactCaseInsensitiveTag(): void
    {
        $this->repository()->create([
            'url' => 'https://one.example.com',
            'title' => 'One',
            'tags' => ['php'],
        ]);
        $this->repository()->create([
            'url' => 'https://two.example.com',
            'title' => 'Two',
            'tags' => ['phpunit'],
        ]);

        $matches = $this->repository()->findAll('PHP');

        $this->assertCount(1, $matches);
        $this->assertSame('One', $matches[0]['title']);
        $this->assertSame([], $this->repository()->findAll('ph'));
    }

    public function testUpdateChangesFieldsAndRefreshesUpdatedAt(): void
    {
        $created = $this->repository()->create([
            'url' => 'https://example.com',
            'title' => 'Old title',
            'tags' => ['one'],
        ]);
        sleep(1);

        $updated = $this->repository()->update((int) $created['id'], [
            'title' => 'New title',
        ]);

        $this->assertNotNull($updated);
        $this->assertSame('New title', $updated['title']);
        $this->assertSame('https://example.com', $updated['url']);
        $this->assertSame(['one'], $updated['tags']);
        $this->assertSame($created['created_at'], $updated['created_at']);
        $this->assertGreaterThan(
            strtotime((string) $created['updated_at']),
            strtotime((string) $updated['updated_at'])
        );
    }

    public function testUpdateReplacesTagsWhenProvided(): void
    {
        $created = $this->repository()->create([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['old'],
        ]);

        $updated = $this->repository()->update((int) $created['id'], [
            'tags' => [' New ', 'new', 'again'],
        ]);

        $this->assertNotNull($updated);
        $this->assertSame(['new', 'again'], $updated['tags']);
    }

    public function testUpdateReturnsNullForUnknownId(): void
    {
        $this->assertNull($this->repository()->update(999999, ['title' => 'X']));
    }

    public function testDeleteRemovesTheBookmark(): void
    {
        $created = $this->repository()->create([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => [],
        ]);

        $this->assertTrue($this->repository()->delete((int) $created['id']));
        $this->assertNull($this->repository()->findById((int) $created['id']));
        $this->assertFalse($this->repository()->delete((int) $created['id']));
    }

    public function testPersistenceAcrossRepositoryInstances(): void
    {
        $created = $this->repository()->create([
            'url' => 'https://example.com',
            'title' => 'Survivor',
            'tags' => ['php'],
        ]);

        $secondInstance = new BookmarkRepository(Database::connection());
        $found = $secondInstance->findById((int) $created['id']);

        $this->assertNotNull($found);
        $this->assertSame('Survivor', $found['title']);
        $this->assertSame(['php'], $found['tags']);
    }
}
