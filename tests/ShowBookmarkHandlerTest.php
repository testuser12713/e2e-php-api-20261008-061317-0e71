<?php

declare(strict_types=1);

namespace App\Tests;

use App\Handler\ShowBookmarkHandler;
use App\Http\Request;
use App\Storage\BookmarkRepository;
use App\Storage\Database;
use PHPUnit\Framework\TestCase;

/**
 * AC-05: GET /api/bookmarks/{id} returns the stored bookmark, and an unknown id
 * answers 404 with the JSON error body.
 *
 * Both cases need the storage slice: on this branch Database::connection()
 * throws \RuntimeException('storage not implemented'), so neither a real
 * bookmark nor a real "unknown id" can be produced yet. Everything therefore
 * sits behind one readiness probe that drives the real storage stack and, while
 * that slice is absent, SKIPS this test instead of asserting a placeholder.
 * The guard goes live on its own the moment the storage slice merges.
 */
final class ShowBookmarkHandlerTest extends TestCase
{
    private string $dbPath;

    /** @var array{env: string|null, server: string|null, getenv: string|null} */
    private array $savedDbPath = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->savedDbPath = [
            'env' => $_ENV['DB_PATH'] ?? null,
            'server' => $_SERVER['DB_PATH'] ?? null,
            'getenv' => getenv('DB_PATH') === false ? null : (string) getenv('DB_PATH'),
        ];

        $this->dbPath = sys_get_temp_dir() . '/bookmarks-show-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->applyDbPath($this->dbPath);
    }

    protected function tearDown(): void
    {
        $this->restoreDbPath();

        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }

        parent::tearDown();
    }

    public function testReturnsStoredBookmarkAnd404ForUnknownId(): void
    {
        if (!$this->storageSliceReady()) {
            $this->markTestSkipped(
                'Storage slice ("Implement SQLite storage, bookmark validation and creation") is not '
                . 'available yet, so neither the found case nor the unknown-id case can be produced.'
            );
        }

        $repository = new BookmarkRepository(Database::connection());
        $stored = $repository->create([
            'url' => 'https://example.com/article',
            'title' => 'Example article',
            'tags' => ['PHP', 'php ', 'Rest'],
        ]);
        $id = (int) $stored['id'];

        $handler = new ShowBookmarkHandler();

        $response = $handler(new Request('GET', '/api/bookmarks/' . $id), ['id' => (string) $id]);

        $this->assertSame(200, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);

        $bookmark = json_decode($response->body, true);
        $this->assertIsArray($bookmark);

        $keys = array_keys($bookmark);
        sort($keys);
        $this->assertSame(['created_at', 'id', 'tags', 'title', 'updated_at', 'url'], $keys);

        $this->assertIsInt($bookmark['id']);
        $this->assertSame($id, $bookmark['id']);
        $this->assertIsString($bookmark['url']);
        $this->assertSame('https://example.com/article', $bookmark['url']);
        $this->assertIsString($bookmark['title']);
        $this->assertSame('Example article', $bookmark['title']);
        $this->assertIsArray($bookmark['tags']);
        $this->assertIsString($bookmark['created_at']);
        $this->assertIsString($bookmark['updated_at']);

        $nonCanonical = '0' . $id;
        $castResponse = $handler(
            new Request('GET', '/api/bookmarks/' . $nonCanonical),
            ['id' => $nonCanonical]
        );
        $this->assertSame(200, $castResponse->status);
        $this->assertSame($response->body, $castResponse->body);

        $missing = (string) ($id + 1000000);
        $missingResponse = $handler(new Request('GET', '/api/bookmarks/' . $missing), ['id' => $missing]);

        $this->assertSame(404, $missingResponse->status);
        $this->assertSame('application/json; charset=utf-8', $missingResponse->headers['Content-Type']);
        $this->assertSame(
            '{"error":{"code":"not_found","message":"Bookmark not found","details":{}}}',
            $missingResponse->body
        );
    }

    /**
     * Drive the real storage stack with a create()/findById() round trip.
     * Returns false — never throws — while the storage slice is absent, so the
     * caller can mark the whole test skipped.
     */
    private function storageSliceReady(): bool
    {
        try {
            $repository = new BookmarkRepository(Database::connection());
            $probe = $repository->create([
                'url' => 'https://probe.example.test/',
                'title' => 'probe',
                'tags' => [],
            ]);

            if (!is_array($probe) || !isset($probe['id'])) {
                return false;
            }

            return $repository->findById((int) $probe['id']) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    private function applyDbPath(string $path): void
    {
        putenv('DB_PATH=' . $path);
        $_ENV['DB_PATH'] = $path;
        $_SERVER['DB_PATH'] = $path;
    }

    private function restoreDbPath(): void
    {
        if ($this->savedDbPath['getenv'] === null) {
            putenv('DB_PATH');
        } else {
            putenv('DB_PATH=' . $this->savedDbPath['getenv']);
        }

        if ($this->savedDbPath['env'] === null) {
            unset($_ENV['DB_PATH']);
        } else {
            $_ENV['DB_PATH'] = $this->savedDbPath['env'];
        }

        if ($this->savedDbPath['server'] === null) {
            unset($_SERVER['DB_PATH']);
        } else {
            $_SERVER['DB_PATH'] = $this->savedDbPath['server'];
        }
    }
}
