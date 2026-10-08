<?php

declare(strict_types=1);

namespace App\Tests;

use App\Storage\BookmarkRepository;
use App\Storage\Database;
use PDO;
use PHPUnit\Framework\TestCase;

final class BookmarkRepositoryTest extends TestCase
{
    private string $dir;
    private string $path;
    private PDO $pdo;
    private BookmarkRepository $repository;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/bookmarks_repo_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
        $this->path = $this->dir . '/bookmarks.sqlite';
        putenv('DB_PATH=' . $this->path);

        $this->pdo = Database::connection();
        $this->repository = new BookmarkRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        putenv('DB_PATH');
        if (is_file($this->path)) {
            unlink($this->path);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testCreateReturnsBookmarkWithNormalizedTagsAndTimestamps(): void
    {
        $bookmark = $this->repository->create([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => [' PHP ', 'php', 'Php'],
        ]);

        $this->assertIsInt($bookmark['id']);
        $this->assertGreaterThan(0, $bookmark['id']);
        $this->assertSame('https://example.com', $bookmark['url']);
        $this->assertSame('Example', $bookmark['title']);
        $this->assertSame(['php'], $bookmark['tags']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $bookmark['created_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $bookmark['updated_at']);
    }

    public function testCreateWithoutTagsStoresAnEmptyList(): void
    {
        $bookmark = $this->repository->create([
            'url' => 'https://example.com',
            'title' => 'Example',
        ]);

        $this->assertSame([], $bookmark['tags']);
    }

    public function testFindByIdReturnsStoredBookmarkAndNullForUnknownId(): void
    {
        $created = $this->repository->create([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['php'],
        ]);

        $found = $this->repository->findById($created['id']);

        $this->assertNotNull($found);
        $this->assertSame($created['id'], $found['id']);
        $this->assertSame(['php'], $found['tags']);

        $this->assertNull($this->repository->findById(999999));
    }

    public function testFindAllOrdersNewestFirst(): void
    {
        $first = $this->repository->create(['url' => 'https://first.example', 'title' => 'First']);
        $second = $this->repository->create(['url' => 'https://second.example', 'title' => 'Second']);

        $all = $this->repository->findAll(null);

        $this->assertCount(2, $all);
        $this->assertSame($second['id'], $all[0]['id']);
        $this->assertSame($first['id'], $all[1]['id']);
    }

    public function testTagFilterIsExactAndCaseInsensitive(): void
    {
        $php = $this->repository->create(['url' => 'https://a.example', 'title' => 'A', 'tags' => ['PHP']]);
        $this->repository->create(['url' => 'https://b.example', 'title' => 'B', 'tags' => ['phpunit']]);
        $this->repository->create(['url' => 'https://c.example', 'title' => 'C', 'tags' => ['javascript']]);

        $lower = $this->repository->findAll('php');
        $this->assertCount(1, $lower);
        $this->assertSame($php['id'], $lower[0]['id']);

        $mixed = $this->repository->findAll('pHp');
        $this->assertCount(1, $mixed);
        $this->assertSame($php['id'], $mixed[0]['id']);

        $this->assertSame([], $this->repository->findAll('ph'));
        $this->assertSame([], $this->repository->findAll('ruby'));
    }

    public function testTagsAreNormalizedOnWriteAndOnRead(): void
    {
        $created = $this->repository->create([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['  PHP  ', 'php', 'JavaScript', '  ', 'PHP'],
        ]);

        $this->assertSame(['php', 'javascript'], $created['tags']);

        $reloaded = $this->repository->findById($created['id']);
        $this->assertNotNull($reloaded);
        $this->assertSame(['php', 'javascript'], $reloaded['tags']);

        $all = $this->repository->findAll(null);
        $this->assertSame(['php', 'javascript'], $all[0]['tags']);
    }

    public function testUpdateChangesFieldsAndRefreshesUpdatedAtButKeepsAbsentTags(): void
    {
        $created = $this->repository->create([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['php'],
        ]);

        $statement = $this->pdo->prepare('UPDATE bookmarks SET created_at = :c, updated_at = :c WHERE id = :id');
        $statement->execute([':c' => '2000-01-01T00:00:00Z', ':id' => $created['id']]);

        $updated = $this->repository->update($created['id'], ['title' => 'Renamed']);

        $this->assertNotNull($updated);
        $this->assertSame('Renamed', $updated['title']);
        $this->assertSame('https://example.com', $updated['url']);
        $this->assertSame(['php'], $updated['tags']);
        $this->assertSame('2000-01-01T00:00:00Z', $updated['created_at']);
        $this->assertNotSame('2000-01-01T00:00:00Z', $updated['updated_at']);
    }

    public function testUpdateNormalizesProvidedTags(): void
    {
        $created = $this->repository->create([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['php'],
        ]);

        $updated = $this->repository->update($created['id'], ['tags' => [' PHP ', 'Php', 'SQL']]);

        $this->assertNotNull($updated);
        $this->assertSame(['php', 'sql'], $updated['tags']);
    }

    public function testUpdateReturnsNullForUnknownId(): void
    {
        $this->assertNull($this->repository->update(999999, ['title' => 'Nope']));
    }

    public function testDeleteRemovesBookmarkAndReturnsFalseForUnknownId(): void
    {
        $created = $this->repository->create(['url' => 'https://example.com', 'title' => 'Example']);

        $this->assertTrue($this->repository->delete($created['id']));
        $this->assertNull($this->repository->findById($created['id']));
        $this->assertFalse($this->repository->delete($created['id']));
    }

    public function testBookmarksPersistAcrossRepositoryInstances(): void
    {
        $created = $this->repository->create([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['php'],
        ]);

        $secondRepository = new BookmarkRepository(Database::connection());
        $found = $secondRepository->findById($created['id']);

        $this->assertNotNull($found);
        $this->assertSame('Example', $found['title']);
        $this->assertSame(['php'], $found['tags']);
    }
}
