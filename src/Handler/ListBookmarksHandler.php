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
 * The optional `tag` query parameter filters the list to exactly the bookmarks
 * carrying that tag; matching is case-insensitive and exact (no partial hits)
 * and is performed by the repository.
 */
final class ListBookmarksHandler
{
    public function __invoke(Request $request, array $params): Response
    {
        $tag = null;
        if (array_key_exists('tag', $request->query) && is_string($request->query['tag'])) {
            $tag = $request->query['tag'];
        }

        $repository = new BookmarkRepository(Database::connection());

        return Response::json($repository->findAll($tag));
    }
}
