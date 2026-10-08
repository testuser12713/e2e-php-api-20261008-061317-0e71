<?php

declare(strict_types=1);

namespace App\Handler;

use App\Http\Request;
use App\Http\Response;
use App\Storage\BookmarkRepository;
use App\Storage\Database;

/**
 * GET /api/bookmarks — lists the stored bookmarks newest first.
 *
 * An optional ?tag= filter is passed through to the repository, which owns
 * exact, case-insensitive tag matching and the created_at DESC ordering.
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
