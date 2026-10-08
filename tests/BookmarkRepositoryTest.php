<?php

declare(strict_types=1);

namespace App\Tests;

use App\Storage\BookmarkRepository;
use App\Storage\Database;
use PHPUnit\Framework\TestCase;

final class BookmarkRepositoryTest extends TestCase
{
    private string $databasePath = '';

    protected function setUp(): void
    {
        $this->databasePath = sys_get_temp_dir() . '/bookmarks_' . bin2hex(random_bytes(8)) . '.sqlite';
        putenv('DB_PATH=' . $this->databasePath);
    }

    protected function tearDown(): void
    {
        putenv('DB_PATH');
        if ($this->databasePath !== '' && is_file($this->databasePath)) {
            unlink($this->databasePath);
        }
    }

    private function repository(): BookmarkRepository
    {
        return new BookmarkRepository(Database::connection());
    }

    public function testCreateReturnsTheStoredBookmark(): void
    {
        $bookmark = $this->repository()->create([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['php', 'api'],
        ]);

        $this->assertSame(1, $bookmark['id']);
        $this->assertSame('https://example.com', $bookmark['url']);
        $this->assertSame('Example', $bookmark['title']);
        $this->assertSame(['php', 'api'], $bookmark['tags']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $bookmark['created_at']);
        $this->assertSame($bookmark['created_at'], $bookmark['updated_at']);
    }

    public function testCreateNormalizesTags(): void
    {
        $bookmark = $this->repository()->create([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => [' PHP ', 'php', 'Api', 'API'],
        ]);

        $this->assertSame(['php', 'api'], $bookmark['tags']);
    }

    public function testReadNormalizesTagsStoredOnDisk(): void
    {
        $pdo = Database::connection();
        $pdo->exec(
            "INSERT INTO bookmarks (url, title, tags, created_at, updated_at) VALUES "
            . "('https://example.com', 'Example', '[\" PHP \",\"php\",\"Api\"]', '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z')"
        );

        $bookmark = $this->repository()->findById(1);

        $this->assertNotNull($bookmark);
        $this->assertSame(['php', 'api'], $bookmark['tags']);
    }

    public function testFindByIdReturnsNullForUnknownId(): void
    {
        $this->assertNull($this->repository()->findById(999));
    }

    public function testFindAllReturnsNewestFirst(): void
    {
        $repository = $this->repository();
        $first = $repository->create(['url' => 'https://a.example.com', 'title' => 'A']);
        $second = $repository->create(['url' => 'https://b.example.com', 'title' => 'B']);

        $all = $repository->findAll(null);

        $this->assertCount(2, $all);
        $this->assertSame($second['id'], $all[0]['id']);
        $this->assertSame($first['id'], $all[1]['id']);
    }

    public function testFindAllFiltersByTagCaseInsensitivelyAndExactly(): void
    {
        $repository = $this->repository();
        $repository->create(['url' => 'https://a.example.com', 'title' => 'A', 'tags' => ['PHP']]);
        $repository->create(['url' => 'https://b.example.com', 'title' => 'B', 'tags' => ['phpunit']]);
        $repository->create(['url' => 'https://c.example.com', 'title' => 'C', 'tags' => ['php', 'api']]);

        $filtered = $repository->findAll('Php');

        $this->assertCount(2, $filtered);
        $this->assertSame(['https://c.example.com', 'https://a.example.com'], array_column($filtered, 'url'));
    }

    public function testUpdateAppliesFieldsAndRefreshesUpdatedAt(): void
    {
        $repository = $this->repository();
        $created = $repository->create([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['php'],
        ]);

        $updated = $repository->update($created['id'], ['title' => 'Renamed']);

        $this->assertNotNull($updated);
        $this->assertSame('Renamed', $updated['title']);
        $this->assertSame('https://example.com', $updated['url']);
        $this->assertSame(['php'], $updated['tags']);
    }

    public function testUpdateLeavesTagsUntouchedWhenAbsent(): void
    {
        $repository = $this->repository();
        $created = $repository->create([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['php', 'api'],
        ]);

        $updated = $repository->update($created['id'], ['url' => 'https://changed.example.com']);

        $this->assertNotNull($updated);
        $this->assertSame('https://changed.example.com', $updated['url']);
        $this->assertSame(['php', 'api'], $updated['tags']);
    }

    public function testUpdateReturnsNullForUnknownId(): void
    {
        $this->assertNull($this->repository()->update(999, ['title' => 'Nope']));
    }

    public function testDeleteRemovesTheBookmark(): void
    {
        $repository = $this->repository();
        $created = $repository->create(['url' => 'https://example.com', 'title' => 'Example']);

        $this->assertTrue($repository->delete($created['id']));
        $this->assertNull($repository->findById($created['id']));
    }

    public function testDeleteReturnsFalseForUnknownId(): void
    {
        $this->assertFalse($this->repository()->delete(999));
    }

    public function testDataPersistsAcrossTwoRepositoryInstances(): void
    {
        $created = $this->repository()->create([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['php'],
        ]);

        $reopened = $this->repository();
        $found = $reopened->findById($created['id']);

        $this->assertNotNull($found);
        $this->assertSame('https://example.com', $found['url']);
        $this->assertSame(['php'], $found['tags']);
        $this->assertCount(1, $reopened->findAll(null));
    }
}
