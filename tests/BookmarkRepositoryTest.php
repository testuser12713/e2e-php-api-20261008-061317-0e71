<?php

declare(strict_types=1);

namespace App\Tests;

use App\Storage\BookmarkRepository;
use App\Storage\Database;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Covers the SQLite storage layer (AC-11): create, read, update, delete, tag
 * filter, tag normalization and persistence across repository instances.
 */
final class BookmarkRepositoryTest extends TestCase
{
    private string $dbPath;

    private string|false $previousDbPath = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousDbPath = getenv('DB_PATH');

        $this->dbPath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'bookmarks_repository_' . bin2hex(random_bytes(8)) . '.sqlite';
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

    public function testCreateReturnsTheStoredBookmark(): void
    {
        $created = $this->repository()->create([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => [],
        ]);

        $this->assertBookmarkShape($created);
        $this->assertGreaterThan(0, $created['id']);
        $this->assertSame('https://example.com', $created['url']);
        $this->assertSame('Example', $created['title']);
        $this->assertSame([], $created['tags']);
        $this->assertNotSame('', $created['created_at']);
        $this->assertSame($created['created_at'], $created['updated_at']);
    }

    public function testCreateNormalizesTagsOnWrite(): void
    {
        $created = $this->repository()->create([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => [' PHP ', 'php', 'Php', 'SQLite', 'sqlite '],
        ]);

        $this->assertSame(['php', 'sqlite'], $created['tags']);
    }

    public function testFindByIdReturnsTheStoredBookmark(): void
    {
        $repository = $this->repository();
        $created = $repository->create([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['php'],
        ]);

        $found = $repository->findById((int) $created['id']);

        $this->assertIsArray($found);
        $this->assertSame($created, $found);
    }

    public function testFindByIdReturnsNullForAnUnknownId(): void
    {
        $this->assertNull($this->repository()->findById(999999));
    }

    public function testFindAllReturnsNewestFirst(): void
    {
        $repository = $this->repository();
        $first = $repository->create(['url' => 'https://a.example', 'title' => 'A', 'tags' => []]);
        sleep(1);
        $second = $repository->create(['url' => 'https://b.example', 'title' => 'B', 'tags' => []]);

        $all = $repository->findAll(null);

        $this->assertCount(2, $all);
        $this->assertSame($second['id'], $all[0]['id']);
        $this->assertSame($first['id'], $all[1]['id']);
    }

    public function testFindAllFiltersByTagExactlyAndCaseInsensitively(): void
    {
        $repository = $this->repository();
        $php = $repository->create([
            'url' => 'https://php.example',
            'title' => 'PHP',
            'tags' => ['php', 'backend'],
        ]);
        $repository->create([
            'url' => 'https://js.example',
            'title' => 'JS',
            'tags' => ['javascript'],
        ]);

        $filtered = $repository->findAll('PHP');
        $this->assertCount(1, $filtered);
        $this->assertSame($php['id'], $filtered[0]['id']);

        $spaced = $repository->findAll(' php ');
        $this->assertCount(1, $spaced);
        $this->assertSame($php['id'], $spaced[0]['id']);

        $this->assertSame([], $repository->findAll('ph'));
    }

    public function testTagsAreNormalizedOnRead(): void
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare(
            'INSERT INTO bookmarks (url, title, tags, created_at, updated_at) '
            . 'VALUES (:url, :title, :tags, :created_at, :updated_at)'
        );
        $statement->execute([
            ':url' => 'https://example.com',
            ':title' => 'Example',
            ':tags' => '["  PHP ","php","Php","  "]',
            ':created_at' => '2026-01-01T00:00:00Z',
            ':updated_at' => '2026-01-01T00:00:00Z',
        ]);

        $found = $this->repository()->findById((int) $pdo->lastInsertId());

        $this->assertIsArray($found);
        $this->assertSame(['php'], $found['tags']);
    }

    public function testUpdateLeavesTagsUntouchedWhenAbsentAndRefreshesUpdatedAt(): void
    {
        $repository = $this->repository();
        $created = $repository->create([
            'url' => 'https://example.com',
            'title' => 'Old title',
            'tags' => ['php'],
        ]);

        sleep(1);
        $updated = $repository->update((int) $created['id'], ['title' => 'New title']);

        $this->assertIsArray($updated);
        $this->assertBookmarkShape($updated);
        $this->assertSame('New title', $updated['title']);
        $this->assertSame('https://example.com', $updated['url']);
        $this->assertSame(['php'], $updated['tags']);
        $this->assertSame($created['created_at'], $updated['created_at']);
        $this->assertGreaterThan(
            strtotime($created['updated_at']),
            strtotime($updated['updated_at'])
        );
    }

    public function testUpdateNormalizesNewTags(): void
    {
        $repository = $this->repository();
        $created = $repository->create([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['old'],
        ]);

        $updated = $repository->update((int) $created['id'], ['tags' => [' PHP ', 'php']]);

        $this->assertIsArray($updated);
        $this->assertSame(['php'], $updated['tags']);
    }

    public function testUpdateReturnsNullForAnUnknownId(): void
    {
        $this->assertNull($this->repository()->update(999999, ['title' => 'Nope']));
    }

    public function testDeleteRemovesTheBookmark(): void
    {
        $repository = $this->repository();
        $created = $repository->create([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => [],
        ]);

        $this->assertTrue($repository->delete((int) $created['id']));
        $this->assertNull($repository->findById((int) $created['id']));
        $this->assertFalse($repository->delete((int) $created['id']));
    }

    public function testDeleteReturnsFalseForAnUnknownId(): void
    {
        $this->assertFalse($this->repository()->delete(999999));
    }

    public function testDataPersistsAcrossRepositoryInstances(): void
    {
        $first = new BookmarkRepository(Database::connection());
        $created = $first->create([
            'url' => 'https://persisted.example',
            'title' => 'Persisted',
            'tags' => ['php'],
        ]);

        $second = new BookmarkRepository(Database::connection());
        $found = $second->findById((int) $created['id']);

        $this->assertIsArray($found);
        $this->assertSame('Persisted', $found['title']);
        $this->assertSame(['php'], $found['tags']);
    }
}
