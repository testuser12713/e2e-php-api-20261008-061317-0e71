<?php

declare(strict_types=1);

namespace App\Tests;

use App\Handler\ShowBookmarkHandler;
use App\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Covers GET /api/bookmarks/{id} (AC-05): the found id answers 200 with the
 * bookmark, an unknown id answers 404 with the shared JSON error body.
 *
 * DB_PATH is pointed at a throwaway temp file so the handler's production
 * storage stack is never allowed to touch (or create) the repository's real
 * data directory. The storage layer itself is another ticket's file and is
 * still a stub here, so the finder seam supplies the read result; the handler
 * still casts the id and owns the 200/404 branches under test.
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
     * @return array<string, mixed>
     */
    private function bookmark(): array
    {
        return [
            'id' => 7,
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['php'],
            'created_at' => '2026-10-08T00:00:00Z',
            'updated_at' => '2026-10-08T00:00:00Z',
        ];
    }

    public function testReturnsTheBookmarkAsJson(): void
    {
        $bookmark = $this->bookmark();
        $receivedId = null;
        $handler = new ShowBookmarkHandler(static function (int $id) use (&$receivedId, $bookmark): ?array {
            $receivedId = $id;

            return $id === 7 ? $bookmark : null;
        });

        $response = $handler(new Request('GET', '/api/bookmarks/7'), ['id' => '7']);

        $this->assertSame(7, $receivedId);
        $this->assertSame(200, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
        $this->assertSame(
            '{"id":7,"url":"https://example.com","title":"Example","tags":["php"],'
            . '"created_at":"2026-10-08T00:00:00Z","updated_at":"2026-10-08T00:00:00Z"}',
            $response->body
        );
    }

    public function testUnknownIdAnswers404WithTheJsonErrorBody(): void
    {
        $handler = new ShowBookmarkHandler(static fn (int $id): ?array => null);

        $response = $handler(new Request('GET', '/api/bookmarks/999'), ['id' => '999']);

        $this->assertSame(404, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
        $this->assertSame(
            '{"error":{"code":"not_found","message":"Not Found","details":{}}}',
            $response->body
        );
    }

    public function testTheRouteIdIsCastToIntBeforeTheLookup(): void
    {
        $receivedId = null;
        $handler = new ShowBookmarkHandler(static function (int $id) use (&$receivedId): ?array {
            $receivedId = $id;

            return null;
        });

        $handler(new Request('GET', '/api/bookmarks/42'), ['id' => '42']);

        $this->assertSame(42, $receivedId);
    }
}
