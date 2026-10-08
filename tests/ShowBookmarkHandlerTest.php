<?php

declare(strict_types=1);

namespace App\Tests;

use App\Handler\ShowBookmarkHandler;
use App\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the single-bookmark retrieval handler.
 *
 * The storage ticket owns BookmarkRepository and Database, so the handler is
 * exercised with an injected lookup that stands in for findById. The default
 * lookup still talks to BookmarkRepository::findById() in the running product.
 */
final class ShowBookmarkHandlerTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function bookmark(int $id): array
    {
        return [
            'id' => $id,
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['php'],
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
        ];
    }

    public function testKnownIdReturnsTheBookmarkAsJson(): void
    {
        $handler = new ShowBookmarkHandler(
            fn (int $id): ?array => $id === 7 ? $this->bookmark(7) : null
        );

        $response = $handler(new Request('GET', '/api/bookmarks/7'), ['id' => '7']);

        $this->assertSame(200, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
        $this->assertSame($this->bookmark(7), json_decode($response->body, true));
    }

    public function testUnknownIdReturns404WithErrorBody(): void
    {
        $handler = new ShowBookmarkHandler(static fn (int $id): ?array => null);

        $response = $handler(new Request('GET', '/api/bookmarks/999'), ['id' => '999']);

        $this->assertSame(404, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
        $this->assertSame(
            ['error' => ['code' => 'not_found', 'message' => 'Not Found', 'details' => []]],
            json_decode($response->body, true)
        );
    }

    public function testRouteParamIsCastToInt(): void
    {
        $seen = null;
        $handler = new ShowBookmarkHandler(static function (int $id) use (&$seen): ?array {
            $seen = $id;

            return null;
        });

        $handler(new Request('GET', '/api/bookmarks/12'), ['id' => '12']);

        $this->assertSame(12, $seen);
    }
}
