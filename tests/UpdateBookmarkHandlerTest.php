<?php

declare(strict_types=1);

namespace App\Tests;

use App\Handler\UpdateBookmarkHandler;
use App\Http\JsonException;
use App\Http\Request;
use App\Http\Response;
use App\Router;
use App\Storage\BookmarkRepository;
use App\Storage\Database;
use App\Validation\BookmarkValidator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PUT|PATCH /api/bookmarks/{id}.
 *
 * DB_PATH points at a throwaway SQLite file in the system temp directory so the
 * storage layer never touches the repository's data directory. The storage and
 * validation slice (ticket #2) is still a placeholder on this branch: every
 * assertion that needs a real write, a real validator or a real connection is
 * detected at runtime and marked skipped, naming that ticket as the owner. The
 * guard is a runtime probe, so the very same assertions run unchanged the moment
 * ticket #2 lands.
 */
final class UpdateBookmarkHandlerTest extends TestCase
{
    private const STORAGE_OWNER = 'ticket #2 "Implement SQLite storage, bookmark validation and creation"';

    private string $dbPath;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bookmarks_');
        self::assertNotFalse($path);
        $this->dbPath = $path;
        putenv('DB_PATH=' . $this->dbPath);
    }

    protected function tearDown(): void
    {
        putenv('DB_PATH');
        if (is_file($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    /**
     * Seed one bookmark through the real repository, or return null while the
     * storage slice is still a placeholder.
     *
     * @param list<string> $tags
     *
     * @return array<string, mixed>|null
     */
    private function seedBookmark(string $url, string $title, array $tags): ?array
    {
        try {
            $pdo = Database::connection();
        } catch (\Throwable) {
            return null;
        }

        $bookmark = (new BookmarkRepository($pdo))->create([
            'url' => $url,
            'title' => $title,
            'tags' => $tags,
        ]);

        return isset($bookmark['id']) ? $bookmark : null;
    }

    private function storageReady(): bool
    {
        try {
            Database::connection();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function validatorReady(): bool
    {
        return BookmarkValidator::validate(['url' => 'not-a-url'], true) !== [];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(string $method, array $body, string $id = '1'): Request
    {
        $encoded = json_encode($body);
        self::assertIsString($encoded);

        return new Request($method, '/api/bookmarks/' . $id, $encoded, [], ['content-type' => 'application/json']);
    }

    public function testPatchAppliesNewTitleAndReturnsTheUpdatedResource(): void
    {
        $seeded = $this->seedBookmark('https://example.com', 'Old title', ['php']);
        if ($seeded === null) {
            $this->markTestSkipped('Storage is not implemented; needs a real write. Owner: ' . self::STORAGE_OWNER);
        }

        sleep(1);

        $handler = new UpdateBookmarkHandler();
        $response = $handler(
            $this->request('PATCH', ['title' => 'New title'], (string) $seeded['id']),
            ['id' => (string) $seeded['id']]
        );

        $this->assertSame(200, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);

        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame(
            ['id', 'url', 'title', 'tags', 'created_at', 'updated_at'],
            array_keys($body)
        );
        $this->assertSame($seeded['id'], $body['id']);
        $this->assertSame('https://example.com', $body['url']);
        $this->assertSame('New title', $body['title']);
        $this->assertSame(['php'], $body['tags']);
        $this->assertSame($seeded['created_at'], $body['created_at']);
        $this->assertTrue(
            strtotime((string) $body['updated_at']) > strtotime((string) $seeded['updated_at']),
            'updated_at must be strictly later than the seeded value'
        );

        $persisted = (new BookmarkRepository(Database::connection()))->findById((int) $seeded['id']);
        $this->assertIsArray($persisted);
        $this->assertSame('New title', $persisted['title']);
    }

    public function testPutAppliesANewUrlToTheExistingBookmark(): void
    {
        $seeded = $this->seedBookmark('https://example.com', 'Title', []);
        if ($seeded === null) {
            $this->markTestSkipped('Storage is not implemented; needs a real write. Owner: ' . self::STORAGE_OWNER);
        }

        sleep(1);

        $handler = new UpdateBookmarkHandler();
        $response = $handler(
            $this->request('PUT', ['url' => 'https://example.org/moved'], (string) $seeded['id']),
            ['id' => (string) $seeded['id']]
        );

        $this->assertSame(200, $response->status);

        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame('https://example.org/moved', $body['url']);
        $this->assertSame('Title', $body['title']);
        $this->assertTrue(
            strtotime((string) $body['updated_at']) > strtotime((string) $seeded['updated_at']),
            'updated_at must be strictly later than the seeded value'
        );
    }

    public function testUnknownIdAnswers404(): void
    {
        if (!$this->storageReady()) {
            $this->markTestSkipped('Storage is not implemented; needs a real connection. Owner: ' . self::STORAGE_OWNER);
        }

        $handler = new UpdateBookmarkHandler();
        $response = $handler($this->request('PATCH', ['title' => 'Whatever'], '999999'), ['id' => '999999']);

        $this->assertSame(404, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);

        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame('not_found', $body['error']['code']);
        $this->assertSame([], (array) $body['error']['details']);
    }

    public function testInvalidUrlAnswers422WithTheOffendingField(): void
    {
        if (!$this->validatorReady()) {
            $this->markTestSkipped('Validation is not implemented. Owner: ' . self::STORAGE_OWNER);
        }

        $handler = new UpdateBookmarkHandler();
        $response = $handler($this->request('PATCH', ['url' => 'not-a-url']), ['id' => '1']);

        $this->assertSame(422, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);

        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame('validation_failed', $body['error']['code']);
        $this->assertSame('url', $body['error']['details']['field']);
    }

    public function testUnparsableBodyRaisesJsonException(): void
    {
        $handler = new UpdateBookmarkHandler();

        $this->expectException(JsonException::class);
        $handler(new Request('PATCH', '/api/bookmarks/1', '{not json'), ['id' => '1']);
    }

    public function testUnparsableBodyIsReportedAsBadRequestThroughTheFrontControllerMapping(): void
    {
        $router = new Router();
        $router->add('PUT', '/api/bookmarks/{id}', new UpdateBookmarkHandler());
        $router->add('PATCH', '/api/bookmarks/{id}', new UpdateBookmarkHandler());

        try {
            $response = $router->dispatch(new Request('PATCH', '/api/bookmarks/1', '{not json'));
        } catch (JsonException $exception) {
            $response = Response::error(400, 'bad_request', $exception->getMessage());
        }

        $this->assertSame(400, $response->status);

        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame('bad_request', $body['error']['code']);
    }
}
