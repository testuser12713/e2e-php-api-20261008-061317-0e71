<?php

declare(strict_types=1);

namespace App\Handler;

use App\Http\Request;
use App\Http\Response;
use App\Storage\BookmarkRepository;
use App\Storage\Database;

/**
 * DELETE /api/bookmarks/{id} — remove a bookmark.
 */
final class DeleteBookmarkHandler
{
    public function __invoke(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $deleted = (new BookmarkRepository(Database::connection()))->delete($id);

        if ($deleted === false) {
            return Response::error(404, 'not_found', 'Bookmark not found');
        }

        return Response::noContent();
    }
}
