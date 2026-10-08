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
 *
 * The request body may contain any subset of {url, title, tags}; validation runs
 * in partial mode. An invalid value answers 422 naming the offending field, an
 * unknown id answers 404, otherwise the updated resource is returned with 200.
 *
 * Tag normalization and the fresh updated_at are the repository's job; this
 * handler only forwards the validated input.
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
                'Validation Failed',
                ['field' => $errors[0]['field']]
            );
        }

        $bookmark = (new BookmarkRepository(Database::connection()))->update($id, $input);

        if ($bookmark === null) {
            return Response::error(404, 'not_found', 'Not Found');
        }

        return Response::json($bookmark);
    }
}
