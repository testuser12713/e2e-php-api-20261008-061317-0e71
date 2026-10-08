<?php

declare(strict_types=1);

namespace App\Tests;

use App\Storage\BookmarkRepository;
use App\Storage\Database;
use PDO;
use PHPUnit\Framework\TestCase;

final class BookmarkRepositoryTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $path = sys_get_temp_dir() . '/bookmarks_repo_' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->files[] = $path;
        putenv('DB_PATH=' . $path);
    }

    protected function tearDown(): void
    {
        putenv('DB_PATH');
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->files = [];
    }

    private function repository(): BookmarkRepository
    {
        return new BookmarkRepository(Database::connection());
    }

    private function pdo(): PDO
    {
        return Database::connection();
    }

    public function testCreateReturnsNormalizedBookmark(): void
    {
        $repository = $this->repository();

        $bookmark = $repository->create([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => [' PHP ', 'php', 'Php ', 'SQL'],
        ]);

        $this->assertIsInt($bookmark['id']);
        $this->assertSame('https://example.com', $bookmark['url']);
        $this->assertSame('Example', $bookmark['title']);
        $this->assertSame(['php', 'sql'], $bookmark['tags']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $bookmark['created_at']
        );
        $this->assertSame($bookmark['created_at'], $bookmark['updated_at']);
    }

    public function testFindByIdReturnsBookmarkAndNullForUnknownId(): void
    {
        $repository = $this->repository();
        $created = $repository->create(['url' => 'https://a.example', 'title' => 'A', 'tags' => []]);

        $found = $repository->findById($created['id']);

        $this->assertNotNull($found);
        $this->assertSame($created['id'], $found['id']);
        $this->assertSame('A', $found['title']);

        $this->assertNull($repository->findById(999999));
    }

    public function testUpdateChangesFieldsAndRefreshesTimestamp(): void
    {
        $repository = $this->repository();
        $created = $repository->create([
            'url' => 'https://a.example',
            'title' => 'Old',
            'tags' => ['php'],
        ]);

        $this->pdo()->exec(
            "UPDATE bookmarks SET updated_at = '2000-01-01T00:00:00Z' WHERE id = " . $created['id']
        );

        $updated = $repository->update($created['id'], ['title' => 'New']);

        $this->assertNotNull($updated);
        $this->assertSame('New', $updated['title']);
        $this->assertSame('https://a.example', $updated['url']);
        $this->assertSame(['php'], $updated['tags']);
        $this->assertNotSame('2000-01-01T00:00:00Z', $updated['updated_at']);
    }

    public function testUpdateReplacesTagsWhenProvided(): void
    {
        $repository = $this->repository();
        $created = $repository->create(['url' => 'https://a.example', 'title' => 'A', 'tags' => ['php']]);

        $updated = $repository->update($created['id'], ['tags' => [' SQL ', 'sql']]);

        $this->assertNotNull($updated);
        $this->assertSame(['sql'], $updated['tags']);
    }

    public function testUpdateReturnsNullForUnknownId(): void
    {
        $this->assertNull($this->repository()->update(4242, ['title' => 'Nope']));
    }

    public function testDeleteRemovesBookmarkAndReportsUnknownId(): void
    {
        $repository = $this->repository();
        $created = $repository->create(['url' => 'https://a.example', 'title' => 'A', 'tags' => []]);

        $this->assertTrue($repository->delete($created['id']));
        $this->assertNull($repository->findById($created['id']));
        $this->assertFalse($repository->delete($created['id']));
    }

    public function testFindAllOrdersNewestFirst(): void
    {
        $repository = $this->repository();
        $first = $repository->create(['url' => 'https://a.example', 'title' => 'A', 'tags' => []]);
        $second = $repository->create(['url' => 'https://b.example', 'title' => 'B', 'tags' => []]);

        $all = $repository->findAll(null);

        $this->assertCount(2, $all);
        $this->assertSame($second['id'], $all[0]['id']);
        $this->assertSame($first['id'], $all[1]['id']);
    }

    public function testFindAllFiltersByExactCaseInsensitiveTag(): void
    {
        $repository = $this->repository();
        $repository->create(['url' => 'https://a.example', 'title' => 'A', 'tags' => ['php']]);
        $repository->create(['url' => 'https://b.example', 'title' => 'B', 'tags' => ['phpstorm']]);
        $repository->create(['url' => 'https://c.example', 'title' => 'C', 'tags' => ['sql']]);

        $matched = $repository->findAll('PHP');

        $this->assertCount(1, $matched);
        $this->assertSame('A', $matched[0]['title']);
    }

    public function testTagsAreNormalizedWhenReadFromRawStorage(): void
    {
        $repository = $this->repository();
        $created = $repository->create(['url' => 'https://a.example', 'title' => 'A', 'tags' => []]);

        $this->pdo()->exec(
            "UPDATE bookmarks SET tags = '[\" PHP \", \"php\", \"Sql\"]' WHERE id = " . $created['id']
        );

        $found = $repository->findById($created['id']);

        $this->assertNotNull($found);
        $this->assertSame(['php', 'sql'], $found['tags']);
    }

    public function testBookmarksPersistAcrossRepositoryInstances(): void
    {
        $created = $this->repository()->create([
            'url' => 'https://persist.example',
            'title' => 'Persist',
            'tags' => ['php'],
        ]);

        $second = $this->repository()->findById($created['id']);

        $this->assertNotNull($second);
        $this->assertSame('Persist', $second['title']);
    }
}
