<?php

declare(strict_types=1);

namespace App\Handler;

use App\Http\Request;
use App\Http\Response;
use App\Storage\BookmarkRepository;
use App\Storage\Database;

/**
 * GET /api/bookmarks/{id} — returns a single bookmark or a 404 JSON error.
 *
 * The handler owns nothing but the read/404 decision: it casts the route
 * parameter to an int, loads the bookmark from the shared storage stack and
 * renders either the bookmark as JSON or the contract's not_found error. No
 * validation, tag handling or storage logic lives here.
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
