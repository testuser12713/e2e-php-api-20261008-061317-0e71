<?php

declare(strict_types=1);

namespace App\Handler;

use App\Http\Request;
use App\Http\Response;
use App\Storage\BookmarkRepository;
use App\Storage\Database;
use App\Validation\BookmarkValidator;

/**
 * PUT|PATCH /api/bookmarks/{id} — apply the supplied fields to one bookmark.
 *
 * Both verbs accept a partial body: whatever subset of {url, title, tags} is
 * present is applied, the rest is left untouched. The handler holds exactly one
 * code path per call; timestamping and tag normalization belong to the
 * repository and are not repeated here.
 */
final class UpdateBookmarkHandler
{
    public function __invoke(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $input = $request->json();

        $errors = BookmarkValidator::validate($input, true);
        if ($errors !== []) {
            return Response::error(
                422,
                'validation_failed',
                (string) $errors[0]['message'],
                ['field' => $errors[0]['field']]
            );
        }

        $repository = new BookmarkRepository(Database::connection());
        $bookmark = $repository->update($id, $input);
        if ($bookmark === null) {
            return Response::error(404, 'not_found', 'Bookmark not found');
        }

        return Response::json($bookmark);
    }
}
