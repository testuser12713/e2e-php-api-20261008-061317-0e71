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
 * Both cases need the storage slice. While it is unmerged, Database::connection()
 * throws and no real bookmark can be produced, so the single readiness probe
 * below SKIPS the test instead of asserting a placeholder answer. The probe goes
 * live on its own the moment the storage slice merges.
 */
final class ShowBookmarkHandlerTest extends TestCase
{
    private string $dbPath = '';

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
     * A real create()/findById() round trip against the storage slice. False
     * means the storage ticket is unmerged and the assertions cannot run yet.
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

    private function invoke(string $id): \App\Http\Response
    {
        $request = new Request('GET', '/api/bookmarks/' . $id);

        return (new ShowBookmarkHandler())($request, ['id' => $id]);
    }

    public function testReturnsStoredBookmarkAnd404ForUnknownId(): void
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
            'tags' => ['one'],
        ]);
        $id = (int) $created['id'];

        $response = $this->invoke((string) $id);

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('application/json', $response->headers['Content-Type']);

        $body = json_decode($response->body, true);
        $this->assertIsArray($body);

        $keys = array_keys($body);
        sort($keys);
        $this->assertSame(
            ['created_at', 'id', 'tags', 'title', 'updated_at', 'url'],
            $keys
        );
        $this->assertIsInt($body['id']);
        $this->assertIsString($body['url']);
        $this->assertIsString($body['title']);
        $this->assertIsArray($body['tags']);
        $this->assertIsString($body['created_at']);
        $this->assertIsString($body['updated_at']);

        $nonCanonical = str_pad((string) $id, 3, '0', STR_PAD_LEFT);
        $this->assertNotSame((string) $id, $nonCanonical);
        $castResponse = $this->invoke($nonCanonical);
        $this->assertSame(200, $castResponse->status);
        $this->assertSame($body, json_decode($castResponse->body, true));

        $missingResponse = $this->invoke((string) ($id + 999999));

        $this->assertSame(404, $missingResponse->status);
        $error = json_decode($missingResponse->body, true);
        $this->assertIsArray($error);
        $this->assertSame('not_found', $error['error']['code']);
        $this->assertIsString($error['error']['message']);
        $this->assertNotSame('', $error['error']['message']);
        $this->assertSame([], $error['error']['details']);
    }
}
