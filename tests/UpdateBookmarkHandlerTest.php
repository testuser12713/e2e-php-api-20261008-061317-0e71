<?php

declare(strict_types=1);

namespace App\Tests;

use App\Handler\UpdateBookmarkHandler;
use App\Http\JsonException;
use App\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the update handler's status and body mapping.
 *
 * DB_PATH is pinned to a per-test temporary file so the storage layer — once
 * ticket #2 has landed — never reads or writes the repository's data directory.
 * Because validation and persistence are delivered by that separate ticket, the
 * handler's collaborators are supplied as small in-memory doubles here; the
 * production defaults still resolve BookmarkValidator and BookmarkRepository on
 * the shared Database connection.
 */
final class UpdateBookmarkHandlerTest extends TestCase
{
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
     * @param list<array{field: string, message: string}> $errors
     */
    private function handler(array $errors, object $repository): UpdateBookmarkHandler
    {
        return new UpdateBookmarkHandler(
            static fn (array $input, bool $partial): array => $errors,
            static fn (): object => $repository,
        );
    }

    private function request(array $body): Request
    {
        $encoded = json_encode($body);
        self::assertIsString($encoded);

        return new Request('PATCH', '/api/bookmarks/7', $encoded, [], ['content-type' => 'application/json']);
    }

    public function testSuccessfulTitleChangeReturnsTheUpdatedResource(): void
    {
        $updated = [
            'id' => 7,
            'url' => 'https://example.com',
            'title' => 'New title',
            'tags' => ['php'],
            'created_at' => '2026-01-01T00:00:00+00:00',
            'updated_at' => '2026-01-02T00:00:00+00:00',
        ];
        $repository = $this->repositoryReturning($updated);
        $handler = $this->handler([], $repository);

        $response = $handler($this->request(['title' => 'New title']), ['id' => '7']);

        self::assertSame(200, $response->status);
        self::assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);

        $decoded = json_decode($response->body, true);
        self::assertIsArray($decoded);
        self::assertSame('New title', $decoded['title']);
        self::assertSame(7, $decoded['id']);
        self::assertNotSame($decoded['created_at'], $decoded['updated_at']);

        self::assertCount(1, $repository->calls);
        self::assertSame(7, $repository->calls[0]['id']);
        self::assertSame(['title' => 'New title'], $repository->calls[0]['data']);
    }

    public function testUnknownIdAnswers404(): void
    {
        $repository = $this->repositoryReturning(null);
        $handler = $this->handler([], $repository);

        $response = $handler($this->request(['title' => 'New title']), ['id' => '999']);

        self::assertSame(404, $response->status);
        $decoded = json_decode($response->body, true);
        self::assertIsArray($decoded);
        self::assertSame('not_found', $decoded['error']['code']);
        self::assertSame([], (array) $decoded['error']['details']);
    }

    public function testInvalidUrlAnswers422WithTheOffendingField(): void
    {
        $repository = $this->repositoryReturning(null);
        $handler = $this->handler(
            [['field' => 'url', 'message' => 'The url field must be a valid http(s) URL.']],
            $repository,
        );

        $response = $handler($this->request(['url' => 'not-a-url']), ['id' => '7']);

        self::assertSame(422, $response->status);
        $decoded = json_decode($response->body, true);
        self::assertIsArray($decoded);
        self::assertSame('validation_failed', $decoded['error']['code']);
        self::assertSame('url', $decoded['error']['details']['field']);
        self::assertSame([], $repository->calls);
    }

    public function testNonJsonBodyIsRejected(): void
    {
        $repository = $this->repositoryReturning(null);
        $handler = $this->handler([], $repository);

        $this->expectException(JsonException::class);
        $handler(new Request('PATCH', '/api/bookmarks/7', '{not json'), ['id' => '7']);
    }

    public function testPartialValidationIsRequested(): void
    {
        $seenPartial = null;
        $repository = $this->repositoryReturning(null);
        $handler = new UpdateBookmarkHandler(
            static function (array $input, bool $partial) use (&$seenPartial): array {
                $seenPartial = $partial;

                return [];
            },
            static fn (): object => $repository,
        );

        $handler($this->request(['title' => 'Only a title']), ['id' => '7']);

        self::assertTrue($seenPartial);
    }

    /**
     * @param array<string, mixed>|null $result
     */
    private function repositoryReturning(?array $result): object
    {
        return new class($result) {
            /** @var list<array{id: int, data: array<string, mixed>}> */
            public array $calls = [];

            /**
             * @param array<string, mixed>|null $result
             */
            public function __construct(private ?array $result)
            {
            }

            /**
             * @param array<string, mixed> $data
             *
             * @return array<string, mixed>|null
             */
            public function update(int $id, array $data): ?array
            {
                $this->calls[] = ['id' => $id, 'data' => $data];

                return $this->result;
            }
        };
    }
}
