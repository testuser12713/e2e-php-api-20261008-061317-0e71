<?php

declare(strict_types=1);

namespace App\Handler;

use App\Http\Request;
use App\Http\Response;

/**
 * GET /api/bookmarks — placeholder until the listing ticket lands.
 */
final class ListBookmarksHandler
{
    public function __invoke(Request $request, array $params): Response
    {
        return Response::error(501, 'not_implemented', 'Bookmark listing is not implemented yet');
    }
}
