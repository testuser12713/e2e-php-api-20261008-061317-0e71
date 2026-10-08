<?php

declare(strict_types=1);

namespace App\Handler;

use App\Http\Request;
use App\Http\Response;
use App\Storage\BookmarkRepository;
use App\Storage\Database;
use App\Validation\BookmarkValidator;

/**
 * POST /api/bookmarks — validate and store a new bookmark.
 */
final class CreateBookmarkHandler
{
    public function __invoke(Request $request, array $params): Response
    {
        $input = $request->json();
        $errors = BookmarkValidator::validate($input, false);

        if ($errors !== []) {
            return Response::error(
                422,
                'validation_failed',
                $errors[0]['message'],
                ['field' => $errors[0]['field']]
            );
        }

        $bookmark = (new BookmarkRepository(Database::connection()))->create($input);

        return Response::json(
            $bookmark,
            201,
            ['Location' => '/api/bookmarks' . '/' . $bookmark['id']]
        );
    }
}
