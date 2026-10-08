<?php

declare(strict_types=1);

namespace App\Tests;

use App\Handler\ShowBookmarkHandler;
use App\Http\Request;
use App\Http\Response;
use App\Storage\BookmarkRepository;
use App\Storage\Database;
use PHPUnit\Framework\TestCase;

/**
 * Covers GET /api/bookmarks/{id} (AC-05).
 */
final class ShowBookmarkHandlerTest extends TestCase
{
    private string $dbPath;

    private string|false $previousDbPath = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousDbPath = getenv('DB_PATH');

        $this->dbPath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'bookmarks_show_' . bin2hex(random_bytes(8)) . '.sqlite';
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
     * @return array<string, mixed>
     */
    private function seed(string $url, string $title, array $tags = []): array
    {
        return (new BookmarkRepository(Database::connection()))->create([
            'url' => $url,
            'title' => $title,
            'tags' => $tags,
        ]);
    }

    private function invoke(string $id): Response
    {
        $request = new Request('GET', '/api/bookmarks/' . $id);

        return (new ShowBookmarkHandler())($request, ['id' => $id]);
    }

    public function testReturnsStoredBookmarkWithContractShapeAndTypes(): void
    {
        $created = $this->seed('https://example.com', 'Example', [' PHP ', 'php', 'Docs']);

        $response = $this->invoke((string) $created['id']);

        $this->assertSame(200, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);

        $bookmark = json_decode($response->body, true);
        $this->assertIsArray($bookmark);

        $this->assertSame(
            ['id', 'url', 'title', 'tags', 'created_at', 'updated_at'],
            array_keys($bookmark)
        );
        $this->assertIsInt($bookmark['id']);
        $this->assertIsString($bookmark['url']);
        $this->assertIsString($bookmark['title']);
        $this->assertIsArray($bookmark['tags']);
        $this->assertIsString($bookmark['created_at']);
        $this->assertIsString($bookmark['updated_at']);

        $this->assertSame($created['id'], $bookmark['id']);
        $this->assertSame('https://example.com', $bookmark['url']);
        $this->assertSame('Example', $bookmark['title']);
        $this->assertSame(['php', 'docs'], $bookmark['tags']);
        $this->assertSame($created['created_at'], $bookmark['created_at']);
        $this->assertSame($created['updated_at'], $bookmark['updated_at']);
    }

    public function testNonCanonicalIdIsCastToIntAndResolvesTheSameBookmark(): void
    {
        $created = null;
        for ($i = 1; $i <= 17; $i++) {
            $created = $this->seed('https://example.com/' . $i, 'Example ' . $i);
        }

        $this->assertNotNull($created);
        $this->assertSame(17, $created['id']);

        $response = $this->invoke('017');

        $this->assertSame(200, $response->status);
        $bookmark = json_decode($response->body, true);
        $this->assertIsArray($bookmark);
        $this->assertSame($created['id'], $bookmark['id']);
        $this->assertSame('Example 17', $bookmark['title']);
    }

    public function testUnknownIdAnswers404WithJsonErrorBody(): void
    {
        $response = $this->invoke('999999');

        $this->assertSame(404, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
        $this->assertStringContainsString('"details":{}', $response->body);

        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded);
        $this->assertSame([
            'error' => [
                'code' => 'not_found',
                'message' => 'Bookmark not found',
                'details' => [],
            ],
        ], $decoded);
    }
}
