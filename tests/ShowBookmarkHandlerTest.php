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
 *
 * The storage slice ("Implement SQLite storage, bookmark validation and
 * creation") is still unmerged at the time of writing, so every assertion that
 * needs real storage is gated behind a probe that goes live by itself the
 * moment that slice lands. Nothing here asserts a placeholder answer as if it
 * were correct.
 */
final class ShowBookmarkHandlerTest extends TestCase
{
    private ?string $dbPath = null;

    private string|false $previousDbPath = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousDbPath = getenv('DB_PATH');

        $path = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'bookmarks_show_' . bin2hex(random_bytes(8)) . '.sqlite';
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
     * A probe that reports whether the storage slice is live: it opens the
     * connection and performs a real create/findById round trip. False means
     * the storage ticket is unmerged and the retrieval assertions cannot run
     * yet.
     */
    private function storageIsLive(): bool
    {
        try {
            $repository = new BookmarkRepository(Database::connection());
            $created = $repository->create([
                'url' => 'https://probe.example.com',
                'title' => 'Probe',
                'tags' => [],
            ]);

            if (!is_array($created) || !isset($created['id'])) {
                return false;
            }

            $found = $repository->findById((int) $created['id']);

            return is_array($found) && isset($found['id']);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function seedBookmark(array $data): array
    {
        $repository = new BookmarkRepository(Database::connection());

        return $repository->create($data);
    }

    private function invoke(string $id): Response
    {
        $request = new Request('GET', '/api/bookmarks/' . $id, '');

        return (new ShowBookmarkHandler())($request, ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $bookmark
     */
    private function assertBookmarkShape(array $bookmark): void
    {
        $keys = array_keys($bookmark);
        sort($keys);
        $this->assertSame(
            ['created_at', 'id', 'tags', 'title', 'updated_at', 'url'],
            $keys
        );
        $this->assertIsInt($bookmark['id']);
        $this->assertIsString($bookmark['url']);
        $this->assertIsString($bookmark['title']);
        $this->assertIsArray($bookmark['tags']);
        $this->assertIsString($bookmark['created_at']);
        $this->assertIsString($bookmark['updated_at']);
    }

    public function testFoundBookmarkAnswers200WithTheStoredBookmark(): void
    {
        if (!$this->storageIsLive()) {
            $this->markTestSkipped(
                'Single bookmark retrieval requires the unmerged slice '
                . '"Implement SQLite storage, bookmark validation and creation".'
            );
        }

        $created = $this->seedBookmark([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['one', 'two'],
        ]);

        $response = $this->invoke((string) $created['id']);

        $this->assertSame(200, $response->status);
        $this->assertArrayHasKey('Content-Type', $response->headers);
        $this->assertStringStartsWith('application/json', $response->headers['Content-Type']);

        $bookmark = json_decode($response->body, true);
        $this->assertIsArray($bookmark);
        $this->assertBookmarkShape($bookmark);
        $this->assertSame((int) $created['id'], $bookmark['id']);
        $this->assertSame('https://example.com', $bookmark['url']);
        $this->assertSame('Example', $bookmark['title']);
        $this->assertSame($created['tags'], $bookmark['tags']);
        $this->assertSame($created['created_at'], $bookmark['created_at']);
        $this->assertSame($created['updated_at'], $bookmark['updated_at']);
    }

    public function testNonCanonicalIdResolvesToTheSameBookmark(): void
    {
        if (!$this->storageIsLive()) {
            $this->markTestSkipped(
                'Single bookmark retrieval requires the unmerged slice '
                . '"Implement SQLite storage, bookmark validation and creation".'
            );
        }

        $created = $this->seedBookmark([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => [],
        ]);

        $canonical = $this->invoke((string) $created['id']);
        $padded = $this->invoke('0' . $created['id']);

        $this->assertSame(200, $padded->status);
        $this->assertSame($canonical->body, $padded->body);
    }

    public function testUnknownIdAnswers404(): void
    {
        if (!$this->storageIsLive()) {
            $this->markTestSkipped(
                'Single bookmark retrieval requires the unmerged slice '
                . '"Implement SQLite storage, bookmark validation and creation".'
            );
        }

        $this->seedBookmark(['url' => 'https://example.com', 'title' => 'Example', 'tags' => []]);

        $response = $this->invoke('999999');

        $this->assertSame(404, $response->status);
        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded);
        $this->assertSame('not_found', $decoded['error']['code']);
        $this->assertSame('Bookmark not found', $decoded['error']['message']);
        $this->assertSame([], $decoded['error']['details']);
    }
}
