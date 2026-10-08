<?php

declare(strict_types=1);

namespace App\Handler;

use App\Http\Request;
use App\Http\Response;

/**
 * PUT|PATCH /api/bookmarks/{id} — placeholder until the update ticket lands.
 */
final class UpdateBookmarkHandler
{
    public function __invoke(Request $request, array $params): Response
    {
        return Response::error(501, 'not_implemented', 'Not Implemented');
    }
}
