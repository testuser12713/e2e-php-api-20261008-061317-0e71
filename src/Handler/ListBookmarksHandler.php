<?php

declare(strict_types=1);

namespace App\Handler;

use App\Http\Request;
use App\Http\Response;
use App\Storage\BookmarkRepository;
use App\Storage\Database;

/**
 * GET /api/bookmarks — list the stored bookmarks, newest first.
 *
 * An optional ?tag= filter is handed straight to the repository, which is the
 * single source of truth for the exact, case-insensitive tag matching and the
 * created_at DESC ordering. The handler only normalizes "no/empty tag" to null
 * and serializes the result.
 */
final class ListBookmarksHandler
{
    public function __invoke(Request $request, array $params): Response
    {
        $tag = $request->query['tag'] ?? null;
        if (!is_string($tag) || $tag === '') {
            $tag = null;
        }

        $repository = new BookmarkRepository(Database::connection());

        return Response::json($repository->findAll($tag));
    }
}
