<?php

declare(strict_types=1);

namespace App\Handler;

use App\Http\Request;
use App\Http\Response;
use App\Storage\BookmarkRepository;
use App\Storage\Database;

/**
 * GET /api/bookmarks — list stored bookmarks newest first, optionally
 * filtered by an exact, case-insensitive tag match.
 *
 * Filtering, ordering and tag normalization live in BookmarkRepository, which
 * is the single source of truth for those rules; the handler only decides
 * whether a filter was supplied and delegates.
 */
final class ListBookmarksHandler
{
    public function __invoke(Request $request, array $params): Response
    {
        $tag = $request->query['tag'] ?? null;
        if (!is_string($tag) || trim($tag) === '') {
            $tag = null;
        }

        $bookmarks = (new BookmarkRepository(Database::connection()))->findAll($tag);

        return Response::json($bookmarks);
    }
}
