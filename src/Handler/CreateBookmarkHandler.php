<?php

declare(strict_types=1);

namespace App\Handler;

use App\Http\Request;
use App\Http\Response;
use App\Storage\BookmarkRepository;
use App\Storage\Database;
use App\Validation\BookmarkValidator;

/**
 * POST /api/bookmarks — create a bookmark.
 */
final class CreateBookmarkHandler
{
    public function __invoke(Request $request, array $params): Response
    {
        $input = $request->json();

        $errors = BookmarkValidator::validate($input, false);
        if ($errors !== []) {
            $first = $errors[0];

            return Response::error(422, 'validation_failed', 'Validation failed', ['field' => $first['field']]);
        }

        $repository = new BookmarkRepository(Database::connection());
        $bookmark = $repository->create($input);

        return Response::json($bookmark, 201, ['Location' => '/api/bookmarks' . '/' . $bookmark['id']]);
    }
}
