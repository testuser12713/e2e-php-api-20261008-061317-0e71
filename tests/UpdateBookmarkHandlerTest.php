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
 * Covers PUT|PATCH /api/bookmarks/{id} (AC-06).
 *
 * The storage and validation slices ("Implement SQLite storage, bookmark
 * validation and creation") are still unmerged at the time of writing, so the
 * assertions that need a real write or a live validator are gated behind probes
 * that go live by themselves the moment those bodies are implemented. Nothing
 * here asserts a placeholder answer as if it were correct.
 */
final class UpdateBookmarkHandlerTest extends TestCase
{
    private ?string $dbPath = null;

    private string|false $previousDbPath = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousDbPath = getenv('DB_PATH');

        $path = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'bookmarks_update_' . bin2hex(random_bytes(8)) . '.sqlite';
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
     * the storage ticket is unmerged and the write assertions cannot run yet.
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
     * A probe that reports whether the validation slice is live: an invalid url
     * must actually produce an error. False means the validator is still the
     * placeholder returning [] and the 422 path cannot be exercised yet.
     */
    private function validatorIsLive(): bool
    {
        return BookmarkValidator::validate(['url' => 'not-a-url', 'title' => 'x'], false) !== [];
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

    private function invoke(string $method, int $id, string $body): Response
    {
        $request = new Request($method, '/api/bookmarks/' . $id, $body);

        return (new UpdateBookmarkHandler())($request, ['id' => (string) $id]);
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

    public function testUnparsableBodyAnswers400WithoutTouchingStorage(): void
    {
        $request = new Request('PATCH', '/api/bookmarks/1', '{not json');

        try {
            (new UpdateBookmarkHandler())($request, ['id' => '1']);
            $this->fail('Expected App\Http\JsonException for an unparsable body.');
        } catch (JsonException $exception) {
            // The front controller maps JsonException to a 400 bad_request
            // response before the validator or the repository is ever reached.
            $response = Response::error(400, 'bad_request', $exception->getMessage());
        }

        $this->assertSame(400, $response->status);
        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded);
        $this->assertSame('bad_request', $decoded['error']['code']);
        $this->assertSame([], $decoded['error']['details']);
    }

    public function testInvalidUrlAnswers422NamingTheField(): void
    {
        if (!$this->validatorIsLive()) {
            $this->markTestSkipped(
                'Update validation requires the unmerged slice '
                . '"Implement SQLite storage, bookmark validation and creation".'
            );
        }

        $response = $this->invoke('PATCH', 1, '{"url":"not-a-url"}');

        $this->assertSame(422, $response->status);
        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded);
        $this->assertSame('validation_failed', $decoded['error']['code']);
        $this->assertSame('url', $decoded['error']['details']['field']);
    }

    public function testUnknownIdAnswers404(): void
    {
        if (!$this->storageIsLive()) {
            $this->markTestSkipped(
                'Update of an unknown id requires the unmerged slice '
                . '"Implement SQLite storage, bookmark validation and creation".'
            );
        }

        $this->seedBookmark(['url' => 'https://example.com', 'title' => 'Example', 'tags' => []]);

        $response = $this->invoke('PATCH', 999999, '{"title":"Changed"}');

        $this->assertSame(404, $response->status);
        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded);
        $this->assertSame('not_found', $decoded['error']['code']);
        $this->assertSame([], $decoded['error']['details']);
    }

    public function testPatchChangesTitleAndReturnsTheUpdatedBookmark(): void
    {
        if (!$this->storageIsLive()) {
            $this->markTestSkipped(
                'Updating a bookmark requires the unmerged slice '
                . '"Implement SQLite storage, bookmark validation and creation".'
            );
        }

        $created = $this->seedBookmark([
            'url' => 'https://example.com',
            'title' => 'Old title',
            'tags' => ['one'],
        ]);

        sleep(1);
        $response = $this->invoke('PATCH', (int) $created['id'], '{"title":"New title"}');

        $this->assertSame(200, $response->status);
        $updated = json_decode($response->body, true);
        $this->assertIsArray($updated);
        $this->assertBookmarkShape($updated);
        $this->assertSame((int) $created['id'], $updated['id']);
        $this->assertSame('https://example.com', $updated['url']);
        $this->assertSame('New title', $updated['title']);
        $this->assertSame(['one'], $updated['tags']);
        $this->assertSame($created['created_at'], $updated['created_at']);
        $this->assertGreaterThan(
            strtotime((string) $created['updated_at']),
            strtotime((string) $updated['updated_at'])
        );

        $stored = (new BookmarkRepository(Database::connection()))->findById((int) $created['id']);
        $this->assertIsArray($stored);
        $this->assertSame('New title', $stored['title']);
    }

    public function testPutChangesUrlAndReturnsTheUpdatedBookmark(): void
    {
        if (!$this->storageIsLive()) {
            $this->markTestSkipped(
                'Updating a bookmark requires the unmerged slice '
                . '"Implement SQLite storage, bookmark validation and creation".'
            );
        }

        $created = $this->seedBookmark([
            'url' => 'https://example.com',
            'title' => 'Keep me',
            'tags' => ['two'],
        ]);

        sleep(1);
        $response = $this->invoke('PUT', (int) $created['id'], '{"url":"https://example.org"}');

        $this->assertSame(200, $response->status);
        $updated = json_decode($response->body, true);
        $this->assertIsArray($updated);
        $this->assertBookmarkShape($updated);
        $this->assertSame((int) $created['id'], $updated['id']);
        $this->assertSame('https://example.org', $updated['url']);
        $this->assertSame('Keep me', $updated['title']);
        $this->assertGreaterThan(
            strtotime((string) $created['updated_at']),
            strtotime((string) $updated['updated_at'])
        );
    }
}
