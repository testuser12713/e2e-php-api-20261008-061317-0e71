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
 * Covers GET /api/bookmarks (AC-06) — newest-first listing and the exact,
 * case-insensitive tag filter.
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

    /**
     * Seed a bookmark through the repository and pin its timestamps so the
     * ordering assertion does not depend on how fast two inserts happen.
     *
     * @param list<string> $tags
     *
     * @return array<string, mixed>
     */
    private function seed(string $url, string $title, array $tags, string $createdAt): array
    {
        $repository = new BookmarkRepository(Database::connection());
        $bookmark = $repository->create([
            'url' => $url,
            'title' => $title,
            'tags' => $tags,
        ]);

        $statement = Database::connection()->prepare(
            'UPDATE bookmarks SET created_at = :created_at, updated_at = :updated_at WHERE id = :id'
        );
        $statement->execute([
            ':created_at' => $createdAt,
            ':updated_at' => $createdAt,
            ':id' => $bookmark['id'],
        ]);

        $bookmark['created_at'] = $createdAt;
        $bookmark['updated_at'] = $createdAt;

        return $bookmark;
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
    private function decodeList(Response $response): array
    {
        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded);

        /** @var list<array<string, mixed>> $decoded */
        return $decoded;
    }

    public function testListsAllBookmarksNewestFirst(): void
    {
        $older = $this->seed('https://older.example', 'Older', ['php'], '2020-01-01T00:00:00Z');
        $newer = $this->seed('https://newer.example', 'Newer', ['docs'], '2024-06-01T12:00:00Z');

        $response = $this->invoke();

        $this->assertSame(200, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);

        $bookmarks = $this->decodeList($response);

        $this->assertSame([$newer['id'], $older['id']], array_column($bookmarks, 'id'));
        $this->assertSame(['docs'], $bookmarks[0]['tags']);
        $this->assertSame(['php'], $bookmarks[1]['tags']);
    }

    public function testTagFilterIsCaseInsensitiveAndExact(): void
    {
        $php = $this->seed('https://php.example', 'PHP', [' PHP '], '2020-01-01T00:00:00Z');
        $this->seed('https://docs.example', 'Docs', ['docs'], '2021-01-01T00:00:00Z');

        $response = $this->invoke(['tag' => 'PHP']);

        $this->assertSame(200, $response->status);

        $bookmarks = $this->decodeList($response);

        $this->assertSame([$php['id']], array_column($bookmarks, 'id'));
        $this->assertSame(['php'], $bookmarks[0]['tags']);
    }

    public function testPartialTagDoesNotMatch(): void
    {
        $this->seed('https://php.example', 'PHP', ['php'], '2020-01-01T00:00:00Z');

        $response = $this->invoke(['tag' => 'ph']);

        $this->assertSame(200, $response->status);
        $this->assertSame('[]', $response->body);
        $this->assertSame([], $this->decodeList($response));
    }

    public function testAbsentAndEmptyTagReturnEverything(): void
    {
        $this->seed('https://php.example', 'PHP', ['php'], '2020-01-01T00:00:00Z');
        $this->seed('https://docs.example', 'Docs', ['docs'], '2021-01-01T00:00:00Z');

        $absent = $this->decodeList($this->invoke());
        $empty = $this->decodeList($this->invoke(['tag' => '']));

        $this->assertCount(2, $absent);
        $this->assertCount(2, $empty);
    }

    public function testNonStringTagIsTreatedAsNoFilter(): void
    {
        $this->seed('https://php.example', 'PHP', ['php'], '2020-01-01T00:00:00Z');
        $this->seed('https://docs.example', 'Docs', ['docs'], '2021-01-01T00:00:00Z');

        $bookmarks = $this->decodeList($this->invoke(['tag' => ['php']]));

        $this->assertCount(2, $bookmarks);
    }

    public function testEmptyResultAnswers200WithEmptyList(): void
    {
        $response = $this->invoke();

        $this->assertSame(200, $response->status);
        $this->assertSame('[]', $response->body);
    }
}
