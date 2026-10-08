<?php

declare(strict_types=1);

namespace App\Tests;

use App\Handler\ShowBookmarkHandler;
use App\Http\Request;
use App\Storage\BookmarkRepository;
use App\Storage\Database;
use PHPUnit\Framework\TestCase;

/**
 * Covers GET /api/bookmarks/{id} (AC-05): a known id answers 200 with the
 * bookmark JSON, an unknown id answers 404 with the shared error body.
 *
 * DB_PATH is pointed at a throwaway file under sys_get_temp_dir() so the
 * handler's production storage stack can never touch the repository's real data
 * directory, and the file is removed again in tearDown.
 *
 * The storage slice (BookmarkRepository/Database) is still an unimplemented
 * placeholder on this branch: Database::connection() refuses and the repository
 * answers empty. The found case therefore cannot produce a bookmark yet, so it
 * is skipped with the storage slice named as the owner. The guard probes the
 * real storage stack and disappears by itself the moment that slice lands.
 */
final class ShowBookmarkHandlerTest extends TestCase
{
    private string $dbPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $path = tempnam(sys_get_temp_dir(), 'bookmarks_show_');
        $this->dbPath = $path === false ? sys_get_temp_dir() . '/bookmarks_show_test.sqlite' : $path;
        putenv('DB_PATH=' . $this->dbPath);
    }

    protected function tearDown(): void
    {
        putenv('DB_PATH');

        if ($this->dbPath !== '' && is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }

        parent::tearDown();
    }

    /**
     * Whether the storage slice has been implemented yet.
     *
     * It is implemented once the shared storage stack can be opened, a row can
     * be created through BookmarkRepository::create() and read back through
     * findById(). While the repository is the placeholder, create() answers []
     * and findById() answers null, and while Database is the placeholder,
     * connection() refuses — either way this returns false.
     */
    private function storageIsImplemented(): bool
    {
        try {
            $repository = new BookmarkRepository(Database::connection());

            $created = $repository->create([
                'url' => 'https://storage-probe.example.com',
                'title' => 'storage probe',
                'tags' => [],
            ]);

            if (!is_array($created) || !isset($created['id'])) {
                return false;
            }

            return $repository->findById((int) $created['id']) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    public function testReturnsTheSingleBookmarkAsJson(): void
    {
        if (!$this->storageIsImplemented()) {
            $this->markTestSkipped(
                'BookmarkRepository/Database are still the unimplemented placeholder; '
                . 'the found-case read is owned by the "Implement SQLite storage, '
                . 'bookmark validation and creation" slice.'
            );
        }

        $repository = new BookmarkRepository(Database::connection());
        $created = $repository->create([
            'url' => 'https://example.com/php',
            'title' => 'PHP bookmarks',
            'tags' => ['php', 'api'],
        ]);
        $id = (int) $created['id'];

        $handler = new ShowBookmarkHandler();
        $response = $handler(new Request('GET', '/api/bookmarks/' . $id), ['id' => (string) $id]);

        $this->assertSame(200, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);

        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded);

        $expectedKeys = ['id', 'url', 'title', 'tags', 'created_at', 'updated_at'];
        $actualKeys = array_keys($decoded);
        sort($expectedKeys);
        sort($actualKeys);
        $this->assertSame($expectedKeys, $actualKeys);

        $this->assertIsInt($decoded['id']);
        $this->assertSame($id, $decoded['id']);
        $this->assertSame('https://example.com/php', $decoded['url']);
        $this->assertSame('PHP bookmarks', $decoded['title']);
        $this->assertIsArray($decoded['tags']);
        $this->assertIsString($decoded['created_at']);
        $this->assertIsString($decoded['updated_at']);
    }

    public function testTheRouteIdIsCastToIntBeforeTheLookup(): void
    {
        if (!$this->storageIsImplemented()) {
            $this->markTestSkipped(
                'BookmarkRepository/Database are still the unimplemented placeholder; '
                . 'the id cast is only observable through the storage lookup, which is '
                . 'owned by the "Implement SQLite storage, bookmark validation and '
                . 'creation" slice.'
            );
        }

        $repository = new BookmarkRepository(Database::connection());
        $created = $repository->create([
            'url' => 'https://example.com/cast',
            'title' => 'cast',
            'tags' => [],
        ]);
        $id = (int) $created['id'];

        $handler = new ShowBookmarkHandler();
        $response = $handler(new Request('GET', '/api/bookmarks/' . $id), ['id' => (string) $id]);

        $this->assertSame(200, $response->status);
        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded);
        $this->assertSame($id, $decoded['id']);
    }

    public function testUnknownIdAnswers404WithTheJsonErrorBody(): void
    {
        if (!$this->storageIsImplemented()) {
            $this->markTestSkipped(
                'BookmarkRepository/Database are still the unimplemented placeholder, '
                . 'so the handler cannot reach findById(); the not_found route is '
                . 'exercised once the "Implement SQLite storage, bookmark validation '
                . 'and creation" slice lands.'
            );
        }

        $handler = new ShowBookmarkHandler();
        $response = $handler(new Request('GET', '/api/bookmarks/999999'), ['id' => '999999']);

        $this->assertSame(404, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
        $this->assertSame(
            '{"error":{"code":"not_found","message":"Not found","details":{}}}',
            $response->body
        );
    }
}
