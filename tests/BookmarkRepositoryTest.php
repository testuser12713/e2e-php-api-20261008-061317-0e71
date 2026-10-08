<?php

declare(strict_types=1);

namespace App\Tests;

use App\Storage\BookmarkRepository;
use App\Storage\Database;
use PDO;
use PHPUnit\Framework\TestCase;

final class BookmarkRepositoryTest extends TestCase
{
    private string $dbPath = '';

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bookmarks_repo_');
        self::assertIsString($path);
        $this->dbPath = $path;
        putenv('DB_PATH=' . $this->dbPath);
        Database::connection();
    }

    protected function tearDown(): void
    {
        putenv('DB_PATH');
        if ($this->dbPath !== '' && is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    private function repository(): BookmarkRepository
    {
        return new BookmarkRepository(Database::connection());
    }

    private function rawConnection(): PDO
    {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    public function testCreateReturnsTheStoredBookmark(): void
    {
        $bookmark = $this->repository()->create([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['php'],
        ]);

        $this->assertIsInt($bookmark['id']);
        $this->assertGreaterThan(0, $bookmark['id']);
        $this->assertSame('https://example.com', $bookmark['url']);
        $this->assertSame('Example', $bookmark['title']);
        $this->assertSame(['php'], $bookmark['tags']);
        $this->assertNotSame('', $bookmark['created_at']);
        $this->assertNotSame('', $bookmark['updated_at']);
    }

    public function testCreateNormalizesTagsOnWrite(): void
    {
        $bookmark = $this->repository()->create([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => [' PHP ', 'php', '  Php  ', 'Web'],
        ]);

        $this->assertSame(['php', 'web'], $bookmark['tags']);
    }

    public function testTagsAreNormalizedOnRead(): void
    {
        $pdo = $this->rawConnection();
        $pdo->exec(
            "INSERT INTO bookmarks (url, title, tags, created_at, updated_at) "
            . "VALUES ('https://example.com', 'Example', '[\"  PHP \",\"php\",\"  \",\"Php\"]', "
            . "'2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z')"
        );

        $bookmark = $this->repository()->findById((int) $pdo->lastInsertId());

        $this->assertNotNull($bookmark);
        $this->assertSame(['php'], $bookmark['tags']);
    }

    public function testFindAllReturnsNewestFirst(): void
    {
        $repository = $this->repository();
        $first = $repository->create(['url' => 'https://a.example', 'title' => 'A']);
        $second = $repository->create(['url' => 'https://b.example', 'title' => 'B']);
        $third = $repository->create(['url' => 'https://c.example', 'title' => 'C']);

        $ids = array_column($repository->findAll(null), 'id');

        $this->assertSame([$third['id'], $second['id'], $first['id']], $ids);
    }

    public function testFindAllFiltersByTagCaseInsensitivelyAndExactly(): void
    {
        $repository = $this->repository();
        $php = $repository->create(['url' => 'https://a.example', 'title' => 'A', 'tags' => ['php']]);
        $repository->create(['url' => 'https://b.example', 'title' => 'B', 'tags' => ['phpstorm']]);
        $repository->create(['url' => 'https://c.example', 'title' => 'C', 'tags' => ['web']]);

        $ids = array_column($repository->findAll('  PHP  '), 'id');

        $this->assertSame([$php['id']], $ids);
    }

    public function testFindByIdReturnsNullForUnknownId(): void
    {
        $this->assertNull($this->repository()->findById(999));
    }

    public function testUpdateAppliesFieldsAndKeepsAbsentTags(): void
    {
        $repository = $this->repository();
        $bookmark = $repository->create([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['php'],
        ]);

        $updated = $repository->update($bookmark['id'], ['title' => 'Renamed']);

        $this->assertNotNull($updated);
        $this->assertSame('Renamed', $updated['title']);
        $this->assertSame('https://example.com', $updated['url']);
        $this->assertSame(['php'], $updated['tags']);
    }

    public function testUpdateRefreshesUpdatedAtButNotCreatedAt(): void
    {
        $repository = $this->repository();
        $bookmark = $repository->create(['url' => 'https://example.com', 'title' => 'Example']);

        $pdo = $this->rawConnection();
        $pdo->exec("UPDATE bookmarks SET created_at = '2000-01-01T00:00:00Z', updated_at = '2000-01-01T00:00:00Z' WHERE id = " . (int) $bookmark['id']);

        $updated = $repository->update($bookmark['id'], ['title' => 'Renamed']);

        $this->assertNotNull($updated);
        $this->assertSame('2000-01-01T00:00:00Z', $updated['created_at']);
        $this->assertNotSame('2000-01-01T00:00:00Z', $updated['updated_at']);
    }

    public function testUpdateReturnsNullForUnknownId(): void
    {
        $this->assertNull($this->repository()->update(999, ['title' => 'Nope']));
    }

    public function testDeleteRemovesTheBookmark(): void
    {
        $repository = $this->repository();
        $bookmark = $repository->create(['url' => 'https://example.com', 'title' => 'Example']);

        $this->assertTrue($repository->delete($bookmark['id']));
        $this->assertNull($repository->findById($bookmark['id']));
    }

    public function testDeleteReturnsFalseForUnknownId(): void
    {
        $this->assertFalse($this->repository()->delete(999));
    }

    public function testBookmarksPersistAcrossRepositoryInstances(): void
    {
        $created = (new BookmarkRepository(Database::connection()))->create([
            'url' => 'https://example.com',
            'title' => 'Example',
        ]);

        $found = (new BookmarkRepository(Database::connection()))->findById($created['id']);

        $this->assertNotNull($found);
        $this->assertSame($created['id'], $found['id']);
        $this->assertSame('https://example.com', $found['url']);
    }
}
