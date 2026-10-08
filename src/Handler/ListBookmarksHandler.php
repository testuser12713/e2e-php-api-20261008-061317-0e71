<?php

declare(strict_types=1);

namespace App\Handler;

use App\Http\Request;
use App\Http\Response;
use App\Storage\BookmarkRepository;
use App\Storage\Database;

/**
 * GET /api/bookmarks — lists stored bookmarks, optionally filtered by tag.
 */
final class ListBookmarksHandler
{
    public function __invoke(Request $request, array $params): Response
    {
        $tag = $request->query['tag'] ?? null;
        if (!is_string($tag) || $tag === '') {
            $tag = null;
        }

        $bookmarks = (new BookmarkRepository(Database::connection()))->findAll($tag);

        return Response::json($bookmarks);
    }
}
