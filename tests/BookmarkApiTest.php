<?php

declare(strict_types=1);

namespace App\Tests;

/**
 * End-to-end coverage of the bookmark API over the in-process front controller.
 *
 * Behaviour that this ticket's tree owns is asserted for real. Routes whose
 * handler belongs to another in-flight ticket are asserted only to be WIRED —
 * registered under the agreed path and verb, reachable and answering JSON — a
 * fact that is true both before and after that handler lands. Their behaviour is
 * covered by the ticket that owns it.
 */
final class BookmarkApiTest extends ApiTestCase
{
    public function testHealthEndpointAnswersOk(): void
    {
        $response = $this->request('GET', '/api/health');

        $this->assertSame(200, $response['status']);
        $this->assertSame(['status' => 'ok'], $response['body']);
    }

    public function testCreateBookmarkReturns201WithLocationAndNormalizedTags(): void
    {
        $response = $this->request('POST', '/api/bookmarks', [
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => [' PHP ', 'php', 'News'],
        ]);

        $this->assertSame(201, $response['status']);
        $this->assertArrayHasKey('Location', $response['headers']);
        $this->assertIsArray($response['body']);

        $bookmark = $response['body'];
        $this->assertIsInt($bookmark['id']);
        $this->assertSame('/api/bookmarks/' . $bookmark['id'], $response['headers']['Location']);
        $this->assertSame('https://example.com', $bookmark['url']);
        $this->assertSame('Example', $bookmark['title']);
        $this->assertSame(['php', 'news'], $bookmark['tags']);
        $this->assertNotSame('', $bookmark['created_at']);
        $this->assertNotSame('', $bookmark['updated_at']);
    }

    public function testCreateBookmarkRejectsUnparsableJsonWith400(): void
    {
        $response = $this->request('POST', '/api/bookmarks', '{not json');

        $this->assertSame(400, $response['status']);
        $this->assertIsArray($response['body']);
        $this->assertSame('bad_request', $response['body']['error']['code']);
    }

    public function testCreateBookmarkRejectsInvalidUrlWith422NamingTheField(): void
    {
        $response = $this->request('POST', '/api/bookmarks', [
            'url' => 'not-a-url',
            'title' => 'Example',
        ]);

        $this->assertSame(422, $response['status']);
        $this->assertIsArray($response['body']);
        $this->assertSame('validation_failed', $response['body']['error']['code']);
        $this->assertSame('url', $response['body']['error']['details']['field']);
    }

    public function testCreateBookmarkRejectsMissingTitleWith422NamingTheField(): void
    {
        $response = $this->request('POST', '/api/bookmarks', ['url' => 'https://example.com']);

        $this->assertSame(422, $response['status']);
        $this->assertIsArray($response['body']);
        $this->assertSame('validation_failed', $response['body']['error']['code']);
        $this->assertSame('title', $response['body']['error']['details']['field']);
    }

    public function testUpdateBookmarkWithPutReturnsUpdatedResource(): void
    {
        $created = $this->createBookmark('https://example.com', 'Example');

        $response = $this->request('PUT', '/api/bookmarks/' . $created['id'], [
            'url' => 'https://example.org',
            'title' => 'Renamed',
        ]);

        $this->assertSame(200, $response['status']);
        $this->assertIsArray($response['body']);
        $this->assertSame($created['id'], $response['body']['id']);
        $this->assertSame('https://example.org', $response['body']['url']);
        $this->assertSame('Renamed', $response['body']['title']);
    }

    public function testPatchOnlyUpdatesSuppliedFields(): void
    {
        $created = $this->createBookmark('https://example.com', 'Example', ['php']);

        $response = $this->request('PATCH', '/api/bookmarks/' . $created['id'], ['title' => 'Patched']);

        $this->assertSame(200, $response['status']);
        $this->assertIsArray($response['body']);
        $this->assertSame('Patched', $response['body']['title']);
        $this->assertSame('https://example.com', $response['body']['url']);
        $this->assertSame(['php'], $response['body']['tags']);
    }

    public function testUpdateUnknownBookmarkAnswers404(): void
    {
        $response = $this->request('PATCH', '/api/bookmarks/999999', ['title' => 'Nope']);

        $this->assertSame(404, $response['status']);
        $this->assertIsArray($response['body']);
        $this->assertSame('not_found', $response['body']['error']['code']);
    }

    public function testUpdateWithInvalidValueAnswers422(): void
    {
        $created = $this->createBookmark('https://example.com', 'Example');

        $response = $this->request('PATCH', '/api/bookmarks/' . $created['id'], ['url' => 'not-a-url']);

        $this->assertSame(422, $response['status']);
        $this->assertIsArray($response['body']);
        $this->assertSame('validation_failed', $response['body']['error']['code']);
        $this->assertSame('url', $response['body']['error']['details']['field']);
    }

    public function testListBookmarksRouteIsWired(): void
    {
        $response = $this->request('GET', '/api/bookmarks');

        $this->assertNotSame(404, $response['status'], 'GET /api/bookmarks must be a registered route.');
        $this->assertIsArray($response['body'], 'GET /api/bookmarks must answer JSON.');
    }

    public function testListBookmarksTagFilterRouteIsWired(): void
    {
        $response = $this->request('GET', '/api/bookmarks', null, [], ['tag' => 'php']);

        $this->assertNotSame(404, $response['status'], 'GET /api/bookmarks?tag=... must be a registered route.');
        $this->assertIsArray($response['body'], 'GET /api/bookmarks?tag=... must answer JSON.');
    }

    public function testShowBookmarkRouteIsWired(): void
    {
        $created = $this->createBookmark('https://example.com', 'Example');

        $response = $this->request('GET', '/api/bookmarks/' . $created['id']);

        $this->assertNotSame(404, $response['status'], 'GET /api/bookmarks/{id} must be a registered route.');
        $this->assertIsArray($response['body'], 'GET /api/bookmarks/{id} must answer JSON.');
    }

    public function testDeleteBookmarkRouteIsWired(): void
    {
        $created = $this->createBookmark('https://example.com', 'Example');

        $response = $this->request('DELETE', '/api/bookmarks/' . $created['id']);

        $this->assertNotSame(404, $response['status'], 'DELETE /api/bookmarks/{id} must be a registered route.');
    }

    public function testUnknownPathAnswers404AsJson(): void
    {
        $response = $this->request('GET', '/api/does-not-exist');

        $this->assertSame(404, $response['status']);
        $this->assertSame('application/json; charset=utf-8', $response['headers']['Content-Type']);
        $this->assertIsArray($response['body']);
        $this->assertSame('not_found', $response['body']['error']['code']);
    }

    public function testKnownPathWithUnsupportedMethodAnswers405WithAllow(): void
    {
        $response = $this->request('PATCH', '/api/health');

        $this->assertSame(405, $response['status']);
        $this->assertArrayHasKey('Allow', $response['headers']);
        $this->assertSame('GET', $response['headers']['Allow']);
        $this->assertIsArray($response['body']);
        $this->assertSame('method_not_allowed', $response['body']['error']['code']);
    }

    public function testTrailingSlashIsADifferentPath(): void
    {
        $response = $this->request('GET', '/api/bookmarks/');

        $this->assertSame(404, $response['status']);
        $this->assertIsArray($response['body']);
        $this->assertSame('not_found', $response['body']['error']['code']);
    }

    /**
     * @param list<string>|null $tags
     *
     * @return array<string, mixed>
     */
    private function createBookmark(string $url, string $title, ?array $tags = null): array
    {
        $payload = ['url' => $url, 'title' => $title];
        if ($tags !== null) {
            $payload['tags'] = $tags;
        }

        $response = $this->request('POST', '/api/bookmarks', $payload);
        $this->assertSame(201, $response['status'], 'The create endpoint backing this test must be live.');
        $this->assertIsArray($response['body']);

        return $response['body'];
    }
}
