<?php

declare(strict_types=1);

namespace App\Handler;

use App\Http\Request;
use App\Http\Response;
use App\Storage\BookmarkRepository;
use App\Storage\Database;
use App\Validation\BookmarkValidator;

/**
 * POST /api/bookmarks — validate the body and store a new bookmark.
 */
final class CreateBookmarkHandler
{
    public function __invoke(Request $request, array $params): Response
    {
        $input = $request->json();

        $errors = BookmarkValidator::validate($input);
        if ($errors !== []) {
            return Response::error(
                422,
                'validation_failed',
                $errors[0]['message'],
                ['field' => $errors[0]['field']]
            );
        }

        $repository = new BookmarkRepository(Database::connection());
        $bookmark = $repository->create($input);

        $location = str_replace('{id}', (string) $bookmark['id'], '/api/bookmarks/{id}');

        return Response::json($bookmark, 201, [
            'Location' => $location,
        ]);
    }
}
