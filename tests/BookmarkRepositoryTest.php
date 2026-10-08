<?php

declare(strict_types=1);

namespace App\Tests;

use App\Storage\BookmarkRepository;
use App\Storage\Database;
use PHPUnit\Framework\TestCase;

final class BookmarkRepositoryTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/bookmarks_repo_' . bin2hex(random_bytes(6)) . '.sqlite';
        putenv('DB_PATH=' . $this->dbPath);
    }

    protected function tearDown(): void
    {
        putenv('DB_PATH');
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    private function repository(): BookmarkRepository
    {
        return new BookmarkRepository(Database::connection());
    }

    public function testCreateReturnsStoredBookmarkWithIdAndTimestamps(): void
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

    public function testFindByIdReturnsTheStoredBookmark(): void
    {
        $repository = $this->repository();
        $created = $repository->create(['url' => 'https://example.com', 'title' => 'Example', 'tags' => []]);

        $found = $repository->findById((int) $created['id']);

        $this->assertNotNull($found);
        $this->assertSame($created['id'], $found['id']);
        $this->assertSame('Example', $found['title']);
    }

    public function testFindByIdReturnsNullForUnknownId(): void
    {
        $this->assertNull($this->repository()->findById(123456));
    }

    public function testUpdateAppliesOnlySuppliedFields(): void
    {
        $repository = $this->repository();
        $created = $repository->create(['url' => 'https://example.com', 'title' => 'Old', 'tags' => ['php']]);

        $updated = $repository->update((int) $created['id'], ['title' => 'New']);

        $this->assertNotNull($updated);
        $this->assertSame('New', $updated['title']);
        $this->assertSame('https://example.com', $updated['url']);
        $this->assertSame(['php'], $updated['tags']);
    }

    public function testUpdateNormalizesTags(): void
    {
        $repository = $this->repository();
        $created = $repository->create(['url' => 'https://example.com', 'title' => 'Example', 'tags' => []]);

        $updated = $repository->update((int) $created['id'], ['tags' => [' PHP ', 'php', 'Api']]);

        $this->assertNotNull($updated);
        $this->assertSame(['php', 'api'], $updated['tags']);
    }

    public function testUpdateReturnsNullForUnknownId(): void
    {
        $this->assertNull($this->repository()->update(987654, ['title' => 'New']));
    }

    public function testDeleteRemovesTheBookmark(): void
    {
        $repository = $this->repository();
        $created = $repository->create(['url' => 'https://example.com', 'title' => 'Example', 'tags' => []]);

        $this->assertTrue($repository->delete((int) $created['id']));
        $this->assertNull($repository->findById((int) $created['id']));
        $this->assertFalse($repository->delete((int) $created['id']));
    }

    public function testFindAllReturnsNewestFirst(): void
    {
        $repository = $this->repository();
        $first = $repository->create(['url' => 'https://one.example', 'title' => 'One', 'tags' => []]);
        usleep(1000);
        $second = $repository->create(['url' => 'https://two.example', 'title' => 'Two', 'tags' => []]);

        $all = $repository->findAll(null);

        $this->assertCount(2, $all);
        $this->assertSame($second['id'], $all[0]['id']);
        $this->assertSame($first['id'], $all[1]['id']);
    }

    public function testFindAllFiltersByTagCaseInsensitivelyAndExactly(): void
    {
        $repository = $this->repository();
        $withPhpAndApi = $repository->create(['url' => 'https://a.example', 'title' => 'A', 'tags' => ['php', 'api']]);
        usleep(1000);
        $withPhp = $repository->create(['url' => 'https://b.example', 'title' => 'B', 'tags' => ['Php']]);
        usleep(1000);
        $withPhpMyAdmin = $repository->create(['url' => 'https://c.example', 'title' => 'C', 'tags' => ['phpmyadmin']]);

        $filtered = $repository->findAll('PHP');

        $this->assertCount(2, $filtered);
        $this->assertSame($withPhp['id'], $filtered[0]['id']);
        $this->assertSame($withPhpAndApi['id'], $filtered[1]['id']);

        $apiOnly = $repository->findAll('API');
        $this->assertCount(1, $apiOnly);
        $this->assertSame($withPhpAndApi['id'], $apiOnly[0]['id']);

        $this->assertCount(3, $repository->findAll(null));
    }

    public function testTagsAreNormalizedOnWriteAndRead(): void
    {
        $repository = $this->repository();
        $created = $repository->create([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => [' PHP ', 'php', 'Php', 'Ruby '],
        ]);

        $this->assertSame(['php', 'ruby'], $created['tags']);

        $found = $repository->findById((int) $created['id']);
        $this->assertNotNull($found);
        $this->assertSame(['php', 'ruby'], $found['tags']);
    }

    public function testPersistenceAcrossTwoInstances(): void
    {
        $created = $this->repository()->create(['url' => 'https://example.com', 'title' => 'Example', 'tags' => ['php']]);

        $fresh = $this->repository();
        $found = $fresh->findById((int) $created['id']);

        $this->assertNotNull($found);
        $this->assertSame('Example', $found['title']);
        $this->assertSame(['php'], $found['tags']);
    }
}
