<?php

declare(strict_types=1);

namespace App\Handler;

use App\Http\Request;
use App\Http\Response;

/**
 * DELETE /api/bookmarks/{id}
 *
 * Deletes the bookmark with the given id through the storage layer. A missing
 * bookmark answers 404 with the contract's JSON error body; a successful delete
 * answers 204 with an empty body.
 */
final class DeleteBookmarkHandler
{
    public function __invoke(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $deleted = (new \App\Storage\BookmarkRepository(\App\Storage\Database::connection()))->delete($id);

        if ($deleted === false) {
            return Response::error(404, 'not_found', 'Bookmark not found');
        }

        return Response::noContent();
    }
}
