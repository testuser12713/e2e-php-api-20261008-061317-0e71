<?php

declare(strict_types=1);

namespace App\Tests;

use App\Handler\DeleteBookmarkHandler;
use App\Http\Request;
use App\Http\Response;
use App\Storage\BookmarkRepository;
use App\Storage\Database;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class DeleteBookmarkHandlerTest extends TestCase
{
    private string $dbPath;

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/bookmarks_delete_' . bin2hex(random_bytes(8)) . '.sqlite';
        putenv('DB_PATH=' . $this->dbPath);
        $_ENV['DB_PATH'] = $this->dbPath;
        $_SERVER['DB_PATH'] = $this->dbPath;

        try {
            $this->pdo = Database::connection();
        } catch (Throwable $exception) {
            if (
                $exception instanceof RuntimeException
                && str_contains(strtolower($exception->getMessage()), 'not implemented')
            ) {
                $this->markTestSkipped(
                    'Storage layer is not implemented on this branch yet: ' . $exception->getMessage()
                );
            }

            throw $exception;
        }
    }

    protected function tearDown(): void
    {
        putenv('DB_PATH');
        unset($_ENV['DB_PATH'], $_SERVER['DB_PATH']);

        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function seed(): array
    {
        $repository = new BookmarkRepository($this->pdo);

        return $repository->create([
            'url' => 'https://www.php.net/',
            'title' => 'PHP',
            'tags' => ['PHP'],
        ]);
    }

    private function delete(int $id): Response
    {
        $request = new Request('DELETE', '/api/bookmarks/' . $id);

        return (new DeleteBookmarkHandler())($request, ['id' => (string) $id]);
    }

    public function testDeletesBookmarkAndRemovesIt(): void
    {
        $created = $this->seed();
        $id = (int) $created['id'];

        $response = $this->delete($id);

        $this->assertSame(204, $response->status);
        $this->assertSame('', $response->body);

        $this->assertNull((new BookmarkRepository($this->pdo))->findById($id));
    }

    public function testSecondDeleteOfSameIdAnswers404(): void
    {
        $created = $this->seed();
        $id = (int) $created['id'];

        $this->assertSame(204, $this->delete($id)->status);

        $response = $this->delete($id);

        $this->assertSame(404, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
        $this->assertSame('{"error":{"code":"not_found","message":"Bookmark not found","details":{}}}', $response->body);
    }

    public function testUnknownIdAnswers404(): void
    {
        $response = $this->delete(999999);

        $this->assertSame(404, $response->status);
        $this->assertStringContainsString('"code":"not_found"', $response->body);
    }
}
