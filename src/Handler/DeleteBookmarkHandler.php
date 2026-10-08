<?php

declare(strict_types=1);

namespace App\Handler;

use App\Http\Request;
use App\Http\Response;

/**
 * DELETE /api/bookmarks/{id} — placeholder until the deletion ticket lands.
 */
final class DeleteBookmarkHandler
{
    public function __invoke(Request $request, array $params): Response
    {
        return Response::error(501, 'not_implemented', 'Not Implemented');
    }
}
