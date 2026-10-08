<?php

declare(strict_types=1);

namespace App\Handler;

use App\Http\Request;
use App\Http\Response;
use App\Storage\BookmarkRepository;
use App\Storage\Database;
use App\Validation\BookmarkValidator;

/**
 * PUT|PATCH /api/bookmarks/{id} — apply the supplied fields to a bookmark.
 */
final class UpdateBookmarkHandler
{
    public function __invoke(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $input = $request->json();
        $errors = BookmarkValidator::validate($input, true);

        if ($errors !== []) {
            return Response::error(422, 'validation_failed', $errors[0]['message'], ['field' => $errors[0]['field']]);
        }

        $bookmark = (new BookmarkRepository(Database::connection()))->update($id, $input);

        if ($bookmark === null) {
            return Response::error(404, 'not_found', 'Bookmark not found');
        }

        return Response::json($bookmark);
    }
}
