<?php

declare(strict_types=1);

namespace App\Handler;

use App\Http\Request;
use App\Http\Response;

/**
 * GET /api/bookmarks/{id} — placeholder until the retrieval ticket lands.
 */
final class ShowBookmarkHandler
{
    public function __invoke(Request $request, array $params): Response
    {
        return Response::error(501, 'not_implemented', 'Not Implemented');
    }
}
