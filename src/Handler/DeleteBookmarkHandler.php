<?php

declare(strict_types=1);

namespace App\Handler;

use App\Http\Request;
use App\Http\Response;
use App\Storage\BookmarkRepository;
use App\Storage\Database;

/**
 * DELETE /api/bookmarks/{id} — removes a bookmark.
 *
 * A deleted bookmark answers 204 with an empty body. An unknown id (or one
 * that was already deleted) answers 404 with the contract's JSON error body.
 */
final class DeleteBookmarkHandler
{
    public function __invoke(Request $request, array $params): Response
    {
        $id = (int) $params['id'];

        $deleted = (new BookmarkRepository(Database::connection()))->delete($id);

        if ($deleted === false) {
            return Response::error(404, 'not_found', 'Not Found');
        }

        return Response::noContent();
    }
}
