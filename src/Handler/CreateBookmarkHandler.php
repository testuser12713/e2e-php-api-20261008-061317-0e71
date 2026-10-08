<?php

declare(strict_types=1);

namespace App\Handler;

use App\Http\Request;
use App\Http\Response;

/**
 * POST /api/bookmarks — placeholder until the creation ticket lands.
 */
final class CreateBookmarkHandler
{
    public function __invoke(Request $request, array $params): Response
    {
        return Response::error(501, 'not_implemented', 'Not Implemented');
    }
}
