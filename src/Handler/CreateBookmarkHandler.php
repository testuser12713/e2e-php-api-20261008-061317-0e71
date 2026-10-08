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
 *
 * A body that is not valid JSON throws App\Http\JsonException, which the front
 * controller maps to a 400 bad_request response.
 */
final class CreateBookmarkHandler
{
    public function __invoke(Request $request, array $params): Response
    {
        $input = $request->json();

        $errors = BookmarkValidator::validate($input);
        if ($errors !== []) {
            $error = $errors[0];

            return Response::error(
                422,
                'validation_failed',
                $error['message'],
                ['field' => $error['field']]
            );
        }

        $repository = new BookmarkRepository(Database::connection());
        $bookmark = $repository->create($input);

        return Response::json($bookmark, 201, [
            'Location' => '/api/bookmarks' . '/' . $bookmark['id'],
        ]);
    }
}
