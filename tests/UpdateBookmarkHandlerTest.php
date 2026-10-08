<?php

declare(strict_types=1);

namespace App\Tests;

use App\Handler\UpdateBookmarkHandler;
use App\Http\JsonException;
use App\Http\Request;
use App\Http\Response;
use App\Storage\BookmarkRepository;
use App\Storage\Database;
use App\Validation\BookmarkValidator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PUT|PATCH /api/bookmarks/{id}.
 *
 * The storage slice ("Implement SQLite storage, bookmark validation and
 * creation") is not merged on this branch: Database::connection() throws and
 * BookmarkValidator::validate() is a placeholder returning []. Tests that need
 * a live storage or validator are therefore gated on a runtime probe and skip
 * with a reason that names the owning slice. They start running by themselves
 * the moment that slice lands; none of them asserts a placeholder answer.
 */
final class UpdateBookmarkHandlerTest extends TestCase
{
    private const STORAGE_SLICE = 'Implement SQLite storage, bookmark validation and creation';

    private string $dbPath;
    private ?string $originalDbPath;

    protected function setUp(): void
    {
        $env = getenv('DB_PATH');
        $this->originalDbPath = $env === false ? null : $env;

        $this->dbPath = sys_get_temp_dir() . '/bookmarks_update_' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->exportDbPath($this->dbPath);
        $this->removeDbArtifacts();
    }

    protected function tearDown(): void
    {
        $this->removeDbArtifacts();

        if ($this->originalDbPath === null) {
            putenv('DB_PATH');
            unset($_ENV['DB_PATH'], $_SERVER['DB_PATH']);
        } else {
            $this->exportDbPath($this->originalDbPath);
        }
    }

    private function exportDbPath(string $path): void
    {
        putenv('DB_PATH=' . $path);
        $_ENV['DB_PATH'] = $path;
        $_SERVER['DB_PATH'] = $path;
    }

    private function removeDbArtifacts(): void
    {
        foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * True only when the storage slice exposes a working connection and the
     * create()/findById() round trip actually persists a bookmark.
     */
    private function storageIsLive(): bool
    {
        try {
            $pdo = Database::connection();
        } catch (\Throwable) {
            return false;
        }

        try {
            $repository = new BookmarkRepository($pdo);
            $created = $repository->create([
                'url' => 'https://example.com/storage-probe',
                'title' => 'storage probe',
                'tags' => [],
            ]);
            if (!is_array($created) || !isset($created['id'])) {
                return false;
            }

            $found = $repository->findById((int) $created['id']);

            return is_array($found) && ($found['id'] ?? null) === $created['id'];
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * True only when the validator slice actually rejects an invalid URL
     * instead of returning the placeholder's empty error list.
     */
    private function validatorIsLive(): bool
    {
        try {
            return BookmarkValidator::validate(['url' => 'not-a-url', 'title' => 'x'], false) !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $params
     */
    private function invoke(string $method, array $payload, array $params = ['id' => '1']): Response
    {
        $request = new Request($method, '/api/bookmarks/' . ($params['id'] ?? '1'), (string) json_encode($payload));

        return (new UpdateBookmarkHandler())($request, $params);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function seed(array $overrides = []): array
    {
        $repository = new BookmarkRepository(Database::connection());

        return $repository->create(array_merge([
            'url' => 'https://example.com/seed',
            'title' => 'Seed Title',
            'tags' => ['php', 'api'],
        ], $overrides));
    }

    /**
     * @return list<string>
     */
    private function bookmarkKeys(): array
    {
        return ['id', 'url', 'title', 'tags', 'created_at', 'updated_at'];
    }

    /**
     * @param array<string, mixed> $bookmark
     */
    private function assertBookmarkShape(array $bookmark): void
    {
        $this->assertEqualsCanonicalizing($this->bookmarkKeys(), array_keys($bookmark));
        $this->assertIsInt($bookmark['id']);
        $this->assertIsString($bookmark['url']);
        $this->assertIsString($bookmark['title']);
        $this->assertIsArray($bookmark['tags']);
        foreach ($bookmark['tags'] as $tag) {
            $this->assertIsString($tag);
        }
        $this->assertIsString($bookmark['created_at']);
        $this->assertIsString($bookmark['updated_at']);
    }

    /**
     * An unparsable body is rejected before the validator and the repository are
     * reached, so this holds today and stays true once storage lands. The front
     * controller maps the exception to the contract's 400 bad_request.
     */
    public function testUnparsableBodyIsRejectedWithBadRequest(): void
    {
        $request = new Request('PATCH', '/api/bookmarks/1', '{not json');

        $this->expectException(JsonException::class);
        (new UpdateBookmarkHandler())($request, ['id' => '1']);
    }

    public function testInvalidUrlAnswers422WithTheOffendingField(): void
    {
        if (!$this->validatorIsLive()) {
            $this->markTestSkipped(
                'BookmarkValidator is still a placeholder (returns no errors); owned by "'
                . self::STORAGE_SLICE . '". The 422 check becomes active when that slice merges.'
            );
        }

        $response = $this->invoke('PATCH', ['url' => 'not-a-url']);

        $this->assertSame(422, $response->status);
        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded);
        $this->assertSame('validation_failed', $decoded['error']['code']);
        $this->assertSame(['field' => 'url'], $decoded['error']['details']);
    }

    public function testPatchChangesTitleAndReturnsTheUpdatedBookmark(): void
    {
        if (!$this->storageIsLive()) {
            $this->markTestSkipped(
                'Database::connection() throws and BookmarkRepository is a placeholder; owned by "'
                . self::STORAGE_SLICE . '". This write check becomes active when that slice merges.'
            );
        }

        $created = $this->seed(['title' => 'Original Title']);
        $this->assertBookmarkShape($created);
        sleep(1);

        $response = $this->invoke('PATCH', ['title' => 'Updated Title'], ['id' => (string) $created['id']]);

        $this->assertSame(200, $response->status);
        $updated = json_decode($response->body, true);
        $this->assertIsArray($updated);
        $this->assertBookmarkShape($updated);
        $this->assertSame($created['id'], $updated['id']);
        $this->assertSame('Updated Title', $updated['title']);
        $this->assertSame($created['url'], $updated['url']);
        $this->assertGreaterThan(
            strtotime($created['updated_at']),
            strtotime($updated['updated_at'])
        );

        $persisted = (new BookmarkRepository(Database::connection()))->findById((int) $created['id']);
        $this->assertIsArray($persisted);
        $this->assertSame('Updated Title', $persisted['title']);
    }

    public function testPutChangesTheUrlAndReturnsTheUpdatedBookmark(): void
    {
        if (!$this->storageIsLive()) {
            $this->markTestSkipped(
                'Database::connection() throws and BookmarkRepository is a placeholder; owned by "'
                . self::STORAGE_SLICE . '". This write check becomes active when that slice merges.'
            );
        }

        $created = $this->seed();
        sleep(1);

        $newUrl = 'https://changed.example.com/resource';
        $response = $this->invoke('PUT', ['url' => $newUrl], ['id' => (string) $created['id']]);

        $this->assertSame(200, $response->status);
        $updated = json_decode($response->body, true);
        $this->assertIsArray($updated);
        $this->assertBookmarkShape($updated);
        $this->assertSame($newUrl, $updated['url']);
        $this->assertSame($created['title'], $updated['title']);
        $this->assertGreaterThan(
            strtotime($created['updated_at']),
            strtotime($updated['updated_at'])
        );
    }

    public function testUnknownIdAnswers404WithTheErrorShape(): void
    {
        if (!$this->storageIsLive()) {
            $this->markTestSkipped(
                'Database::connection() throws and BookmarkRepository is a placeholder; owned by "'
                . self::STORAGE_SLICE . '". The 404 check becomes active when that slice merges.'
            );
        }

        $response = $this->invoke('PATCH', ['title' => 'Whatever'], ['id' => '999999']);

        $this->assertSame(404, $response->status);
        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded);
        $this->assertSame('not_found', $decoded['error']['code']);
        $this->assertSame([], $decoded['error']['details']);
    }
}
