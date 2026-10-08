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
 * Covers GET /api/bookmarks with and without a tag filter (AC-06).
 */
final class ListBookmarksHandlerTest extends TestCase
{
    private ?string $dbPath = null;

    private string|false $previousDbPath = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousDbPath = getenv('DB_PATH');

        $path = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'bookmarks_list_' . bin2hex(random_bytes(8)) . '.sqlite';
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

    /**
     * Seed a bookmark through the repository and pin its created_at so the
     * ordering assertion does not depend on the wall clock.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function seedBookmark(array $data, string $createdAt): array
    {
        $pdo = Database::connection();
        $repository = new BookmarkRepository($pdo);
        $created = $repository->create($data);

        $statement = $pdo->prepare(
            'UPDATE bookmarks SET created_at = :created_at, updated_at = :updated_at WHERE id = :id'
        );
        $statement->execute([
            ':created_at' => $createdAt,
            ':updated_at' => $createdAt,
            ':id' => $created['id'],
        ]);

        return $repository->findById((int) $created['id']) ?? $created;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function invoke(array $query = []): Response
    {
        $request = new Request('GET', '/api/bookmarks', '', $query);

        return (new ListBookmarksHandler())($request, []);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listed(array $query = []): array
    {
        $response = $this->invoke($query);
        $this->assertSame(200, $response->status);

        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    public function testListsAllBookmarksNewestFirst(): void
    {
        $older = $this->seedBookmark(
            ['url' => 'https://php.example', 'title' => 'PHP', 'tags' => ['php']],
            '2026-01-01T00:00:00Z'
        );
        $newer = $this->seedBookmark(
            ['url' => 'https://js.example', 'title' => 'JS', 'tags' => ['javascript']],
            '2026-01-02T00:00:00Z'
        );

        $bookmarks = $this->listed();

        $this->assertCount(2, $bookmarks);
        $this->assertSame($newer['id'], $bookmarks[0]['id']);
        $this->assertSame($older['id'], $bookmarks[1]['id']);
    }

    public function testFiltersByTagCaseInsensitively(): void
    {
        $php = $this->seedBookmark(
            ['url' => 'https://php.example', 'title' => 'PHP', 'tags' => ['php']],
            '2026-01-01T00:00:00Z'
        );
        $this->seedBookmark(
            ['url' => 'https://js.example', 'title' => 'JS', 'tags' => ['javascript']],
            '2026-01-02T00:00:00Z'
        );

        $bookmarks = $this->listed(['tag' => 'PHP']);

        $this->assertCount(1, $bookmarks);
        $this->assertSame($php['id'], $bookmarks[0]['id']);
    }

    public function testTagFilterDoesNotMatchPartialTags(): void
    {
        $this->seedBookmark(
            ['url' => 'https://php.example', 'title' => 'PHP', 'tags' => ['php']],
            '2026-01-01T00:00:00Z'
        );

        $this->assertSame([], $this->listed(['tag' => 'ph']));
    }

    public function testAbsentAndEmptyTagReturnEverything(): void
    {
        $older = $this->seedBookmark(
            ['url' => 'https://php.example', 'title' => 'PHP', 'tags' => ['php']],
            '2026-01-01T00:00:00Z'
        );
        $newer = $this->seedBookmark(
            ['url' => 'https://js.example', 'title' => 'JS', 'tags' => ['javascript']],
            '2026-01-02T00:00:00Z'
        );

        $this->assertSame(
            [$newer['id'], $older['id']],
            array_column($this->listed(), 'id')
        );
        $this->assertSame(
            [$newer['id'], $older['id']],
            array_column($this->listed(['tag' => '']), 'id')
        );
    }

    public function testEmptyDatabaseAnswersWithEmptyList(): void
    {
        $this->assertSame([], $this->listed());
        $this->assertSame([], $this->listed(['tag' => 'php']));
    }
}
