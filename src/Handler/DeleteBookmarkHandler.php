<?php

declare(strict_types=1);

namespace App\Handler;

use App\Http\Request;
use App\Http\Response;
use App\Storage\BookmarkRepository;
use App\Storage\Database;

/**
 * DELETE /api/bookmarks/{id} — removes a bookmark by id.
 *
 * A missing id answers the contract's 404; a successful delete answers an
 * empty 204 (the endpoint's only legitimately body-less response).
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
