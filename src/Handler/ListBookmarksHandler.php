<?php

declare(strict_types=1);

namespace App\Handler;

use App\Http\Request;
use App\Http\Response;
use App\Storage\BookmarkRepository;
use App\Storage\Database;

/**
 * GET /api/bookmarks — list stored bookmarks, newest first, with an optional
 * exact, case-insensitive tag filter.
 *
 * Filtering, ordering and tag normalization live in BookmarkRepository, which
 * stays the single source of truth for them.
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
