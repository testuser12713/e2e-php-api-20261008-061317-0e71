<?php

declare(strict_types=1);

namespace App\Handler;

use App\Http\Request;
use App\Http\Response;
use App\Storage\BookmarkRepository;
use App\Storage\Database;

/**
 * GET /api/bookmarks/{id} — returns one bookmark or the contract's 404 body.
 *
 * The repository is the only source of the read result: there is deliberately
 * no injection point, so the running product always reads through
 * BookmarkRepository backed by the shared Database connection.
 */
final class ShowBookmarkHandler
{
    public function __invoke(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $bookmark = (new BookmarkRepository(Database::connection()))->findById($id);

        if ($bookmark === null) {
            return Response::error(404, 'not_found', 'Not found');
        }

        return Response::json($bookmark);
    }
}
