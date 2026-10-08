<?php

declare(strict_types=1);

namespace App\Handler;

use App\Http\Request;
use App\Http\Response;
use App\Storage\BookmarkRepository;
use App\Storage\Database;
use App\Validation\BookmarkValidator;

/**
 * POST /api/bookmarks — validate the payload and store a new bookmark.
 *
 * An unparsable body raises App\Http\JsonException, which the front controller
 * maps to a 400 response; validation failures answer 422.
 */
final class CreateBookmarkHandler
{
    public function __invoke(Request $request, array $params): Response
    {
        $data = $request->json();

        $errors = BookmarkValidator::validate($data, false);
        if ($errors !== []) {
            $first = $errors[0];

            return Response::error(422, 'validation_failed', $first['message'], ['field' => $first['field']]);
        }

        $repository = new BookmarkRepository(Database::connection());
        $bookmark = $repository->create($data);

        $location = str_replace('{id}', (string) $bookmark['id'], '/api/bookmarks/{id}');

        return Response::json($bookmark, 201, ['Location' => $location]);
    }
}
