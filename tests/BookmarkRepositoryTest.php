<?php

declare(strict_types=1);

namespace App\Tests;

use App\Storage\BookmarkRepository;
use App\Storage\Database;
use PDO;
use PHPUnit\Framework\TestCase;

final class BookmarkRepositoryTest extends TestCase
{
    private string $path;
    private BookmarkRepository $repository;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/bookmark_repository_' . bin2hex(random_bytes(8)) . '.sqlite';
        putenv('DB_PATH=' . $this->path);
        $this->repository = new BookmarkRepository(Database::connection());
    }

    protected function tearDown(): void
    {
        putenv('DB_PATH');
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testCreateStoresAndReturnsTheBookmarkShape(): void
    {
        $bookmark = $this->repository->create([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['php', 'api'],
        ]);

        $this->assertSame(1, $bookmark['id']);
        $this->assertSame('https://example.com', $bookmark['url']);
        $this->assertSame('Example', $bookmark['title']);
        $this->assertSame(['php', 'api'], $bookmark['tags']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $bookmark['created_at']
        );
        $this->assertSame($bookmark['created_at'], $bookmark['updated_at']);
    }

    public function testFindByIdReturnsTheStoredBookmarkOrNull(): void
    {
        $created = $this->repository->create(['url' => 'https://a.test', 'title' => 'A']);

        $found = $this->repository->findById((int) $created['id']);
        $this->assertNotNull($found);
        $this->assertSame($created['id'], $found['id']);
        $this->assertSame('https://a.test', $found['url']);
        $this->assertSame([], $found['tags']);

        $this->assertNull($this->repository->findById(9999));
    }

    public function testFindAllOrdersNewestFirst(): void
    {
        $first = $this->repository->create(['url' => 'https://1.test', 'title' => 'One']);
        $second = $this->repository->create(['url' => 'https://2.test', 'title' => 'Two']);

        $all = $this->repository->findAll(null);

        $this->assertCount(2, $all);
        $this->assertSame($second['id'], $all[0]['id']);
        $this->assertSame($first['id'], $all[1]['id']);
    }

    public function testFindAllFiltersByTagCaseInsensitivelyAndExactly(): void
    {
        $php = $this->repository->create([
            'url' => 'https://php.test',
            'title' => 'PHP',
            'tags' => ['php', 'web'],
        ]);
        $this->repository->create([
            'url' => 'https://other.test',
            'title' => 'Other',
            'tags' => ['javascript'],
        ]);

        $filtered = $this->repository->findAll('PHP');
        $this->assertCount(1, $filtered);
        $this->assertSame($php['id'], $filtered[0]['id']);

        $this->assertCount(0, $this->repository->findAll('phps'));
    }

    public function testTagsAreNormalizedAndDeduplicated(): void
    {
        $bookmark = $this->repository->create([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => [' PHP ', 'php', 'Web', '  web  ', 'api'],
        ]);

        $this->assertSame(['php', 'web', 'api'], $bookmark['tags']);

        $found = $this->repository->findById((int) $bookmark['id']);
        $this->assertNotNull($found);
        $this->assertSame(['php', 'web', 'api'], $found['tags']);
    }

    public function testUpdateAppliesOnlySuppliedFields(): void
    {
        $created = $this->repository->create([
            'url' => 'https://old.test',
            'title' => 'Old',
            'tags' => ['old'],
        ]);

        $updated = $this->repository->update((int) $created['id'], ['title' => 'New']);

        $this->assertNotNull($updated);
        $this->assertSame('https://old.test', $updated['url']);
        $this->assertSame('New', $updated['title']);
        $this->assertSame(['old'], $updated['tags']);
        $this->assertSame($created['created_at'], $updated['created_at']);
    }

    public function testUpdateReturnsNullForUnknownId(): void
    {
        $this->assertNull($this->repository->update(4242, ['title' => 'Nope']));
    }

    public function testDeleteRemovesTheBookmark(): void
    {
        $created = $this->repository->create(['url' => 'https://gone.test', 'title' => 'Gone']);

        $this->assertTrue($this->repository->delete((int) $created['id']));
        $this->assertNull($this->repository->findById((int) $created['id']));
        $this->assertFalse($this->repository->delete((int) $created['id']));
    }

    public function testDataPersistsAcrossRepositoryInstances(): void
    {
        $created = $this->repository->create(['url' => 'https://persist.test', 'title' => 'Persist']);

        $otherConnection = Database::connection();
        $this->assertInstanceOf(PDO::class, $otherConnection);
        $otherRepository = new BookmarkRepository($otherConnection);

        $found = $otherRepository->findById((int) $created['id']);
        $this->assertNotNull($found);
        $this->assertSame('https://persist.test', $found['url']);
    }
}
