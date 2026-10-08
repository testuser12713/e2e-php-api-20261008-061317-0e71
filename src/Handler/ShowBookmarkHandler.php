<?php

declare(strict_types=1);

namespace App\Handler;

use App\Http\Request;
use App\Http\Response;
use App\Storage\BookmarkRepository;
use App\Storage\Database;

/**
 * GET /api/bookmarks/{id} — returns a single bookmark or the JSON error body
 * with 404 when the id is unknown.
 */
final class ShowBookmarkHandler
{
    /**
     * Resolves a bookmark by its id, or null when it does not exist.
     *
     * @var callable(int): ?array<string, mixed>
     */
    private $findBookmark;

    /**
     * @param (callable(int): ?array<string, mixed>)|null $findBookmark lookup
     *        used to resolve a bookmark by id. The default talks to the shared
     *        BookmarkRepository, so the front controller can construct the
     *        handler without arguments.
     */
    public function __construct(?callable $findBookmark = null)
    {
        $this->findBookmark = $findBookmark ?? static function (int $id): ?array {
            return (new BookmarkRepository(Database::connection()))->findById($id);
        };
    }

    public function __invoke(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $bookmark = ($this->findBookmark)($id);

        if ($bookmark === null) {
            return Response::error(404, 'not_found', 'Not Found');
        }

        return Response::json($bookmark);
    }
}
