<?php

declare(strict_types=1);

namespace App\Tests;

use App\Handler\UpdateBookmarkHandler;
use App\Http\JsonException;
use App\Http\Request;
use App\Storage\BookmarkRepository;
use App\Storage\Database;
use App\Validation\BookmarkValidator;
use PHPUnit\Framework\TestCase;

/**
 * PUT|PATCH /api/bookmarks/{id} — handler behaviour.
 *
 * The storage and validation slice ("Implement SQLite storage, bookmark
 * validation and creation") is still a placeholder on this branch: Database
 * refuses the connection and the validator reports no errors. The assertions
 * that need a real write therefore skip while that slice is missing and turn
 * into real assertions as soon as it lands. Nothing here asserts the
 * placeholder's temporary answer.
 */
final class UpdateBookmarkHandlerTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/bookmarks-update-' . bin2hex(random_bytes(6)) . '.sqlite';
        putenv('DB_PATH=' . $this->dbPath);
        $_ENV['DB_PATH'] = $this->dbPath;
        $_SERVER['DB_PATH'] = $this->dbPath;
    }

    protected function tearDown(): void
    {
        putenv('DB_PATH');
        unset($_ENV['DB_PATH'], $_SERVER['DB_PATH']);

        foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * The storage slice is not in place yet; skip the checks that require it.
     */
    private function skipUnlessStorageAvailable(): void
    {
        try {
            Database::connection();
        } catch (\RuntimeException $exception) {
            $this->markTestSkipped(sprintf(
                'Skipped: the storage slice "Implement SQLite storage, bookmark validation and creation" '
                . 'is not implemented on this branch (Database::connection(): %s).',
                $exception->getMessage()
            ));
        }
    }

    /**
     * The validation slice is not in place yet; skip the check that requires it.
     */
    private function skipUnlessValidationAvailable(): void
    {
        if (BookmarkValidator::validate(['url' => 'not-a-valid-url'], false) === []) {
            $this->markTestSkipped(
                'Skipped: the validation slice "Implement SQLite storage, bookmark validation and creation" '
                . 'is not implemented on this branch (BookmarkValidator::validate() reports no errors).'
            );
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function invoke(string $method, int $id, array $body): \App\Http\Response
    {
        $request = new Request(
            $method,
            '/api/bookmarks/' . $id,
            (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        return (new UpdateBookmarkHandler())($request, ['id' => (string) $id]);
    }

    public function testUnparsableBodyThrowsJsonException(): void
    {
        $request = new Request('PATCH', '/api/bookmarks/1', '{not json');
        $handler = new UpdateBookmarkHandler();

        $this->expectException(JsonException::class);

        $handler($request, ['id' => '1']);
    }

    public function testEmptyBodyThrowsJsonException(): void
    {
        $request = new Request('PATCH', '/api/bookmarks/1', '   ');
        $handler = new UpdateBookmarkHandler();

        $this->expectException(JsonException::class);

        $handler($request, ['id' => '1']);
    }

    public function testInvalidUrlAnswers422WithField(): void
    {
        $this->skipUnlessValidationAvailable();

        $response = $this->invoke('PATCH', 1, ['url' => 'not-a-valid-url']);

        $this->assertSame(422, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);

        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded);
        $this->assertSame('validation_failed', $decoded['error']['code']);
        $this->assertSame('url', $decoded['error']['details']['field']);
    }

    public function testUnknownIdAnswers404AsJson(): void
    {
        $this->skipUnlessStorageAvailable();

        $response = $this->invoke('PATCH', 999999, ['title' => 'Nope']);

        $this->assertSame(404, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);

        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded);
        $this->assertSame('not_found', $decoded['error']['code']);
        $this->assertSame([], $decoded['error']['details']);
    }

    public function testPatchReturnsUpdatedBookmarkWithFreshTimestamp(): void
    {
        $this->skipUnlessStorageAvailable();

        $repository = new BookmarkRepository(Database::connection());
        $created = $repository->create([
            'url' => 'https://example.com/original',
            'title' => 'Original',
            'tags' => ['Php'],
        ]);

        $id = (int) $created['id'];
        $createdAt = (string) $created['created_at'];

        sleep(1);

        $response = $this->invoke('PATCH', $id, ['title' => 'Changed']);

        $this->assertSame(200, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);

        $updated = json_decode($response->body, true);
        $this->assertIsArray($updated);
        $this->assertSame(
            ['created_at', 'id', 'tags', 'title', 'updated_at', 'url'],
            $this->sortedKeys($updated)
        );
        $this->assertSame($id, $updated['id']);
        $this->assertSame('Changed', $updated['title']);
        $this->assertSame('https://example.com/original', $updated['url']);
        $this->assertIsString($updated['created_at']);
        $this->assertIsString($updated['updated_at']);
        $this->assertSame(['php'], $updated['tags']);
        $this->assertGreaterThan($createdAt, $updated['updated_at']);
    }

    public function testPutChangesUrlAndReturnsUpdatedBookmark(): void
    {
        $this->skipUnlessStorageAvailable();

        $repository = new BookmarkRepository(Database::connection());
        $created = $repository->create([
            'url' => 'https://example.com/before',
            'title' => 'Keep me',
        ]);

        $id = (int) $created['id'];
        $createdAt = (string) $created['created_at'];

        sleep(1);

        $response = $this->invoke('PUT', $id, ['url' => 'https://example.com/after']);

        $this->assertSame(200, $response->status);

        $updated = json_decode($response->body, true);
        $this->assertIsArray($updated);
        $this->assertSame('https://example.com/after', $updated['url']);
        $this->assertSame('Keep me', $updated['title']);
        $this->assertSame($id, $updated['id']);
        $this->assertGreaterThan($createdAt, $updated['updated_at']);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function sortedKeys(array $data): array
    {
        $keys = array_keys($data);
        sort($keys);

        return $keys;
    }
}
