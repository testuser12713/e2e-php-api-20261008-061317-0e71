<?php

declare(strict_types=1);

namespace App\Handler;

use App\Http\Request;
use App\Http\Response;
use App\Storage\BookmarkRepository;
use App\Storage\Database;

/**
 * GET /api/bookmarks/{id} — return a single stored bookmark or 404.
 */
final class ShowBookmarkHandler
{
    public function __invoke(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $bookmark = (new BookmarkRepository(Database::connection()))->findById($id);

        if ($bookmark === null) {
            return Response::error(404, 'not_found', 'Bookmark not found');
        }

        return Response::json($bookmark);
    }
}
