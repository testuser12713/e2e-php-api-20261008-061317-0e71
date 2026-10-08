<?php

declare(strict_types=1);

namespace App\Handler;

use App\Http\Request;
use App\Http\Response;
use App\Storage\BookmarkRepository;
use App\Storage\Database;

/**
 * DELETE /api/bookmarks/{id} — deletes a bookmark, 404 when it does not exist.
 */
final class DeleteBookmarkHandler
{
    public function __invoke(Request $request, array $params): Response
    {
        $repository = new BookmarkRepository(Database::connection());

        $id = (int) ($params['id'] ?? 0);

        if (!$repository->delete($id)) {
            return Response::error(404, 'not_found', 'Bookmark not found');
        }

        return Response::noContent();
    }
}
