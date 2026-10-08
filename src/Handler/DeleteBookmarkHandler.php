<?php

declare(strict_types=1);

namespace App\Handler;

use App\Http\Request;
use App\Http\Response;
use App\Storage\BookmarkRepository;
use App\Storage\Database;

/**
 * DELETE /api/bookmarks/{id} — removes a bookmark.
 *
 * An unknown id (or an id that was already deleted) answers 404 with the
 * contract's JSON error body; an existing bookmark is deleted and answered
 * with an empty 204 body.
 */
final class DeleteBookmarkHandler
{
    /**
     * Deletion seam. Production leaves it null and deletes through
     * BookmarkRepository::delete(); tests may inject a replacement so the
     * handler contract can be verified independently of the storage layer.
     *
     * @var (\Closure(int): bool)|null
     */
    private ?\Closure $delete;

    /**
     * @param (\Closure(int): bool)|null $delete
     */
    public function __construct(?\Closure $delete = null)
    {
        $this->delete = $delete;
    }

    public function __invoke(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $delete = $this->delete;
        if ($delete === null) {
            $repository = new BookmarkRepository(Database::connection());
            $delete = static fn (int $bookmarkId): bool => $repository->delete($bookmarkId);
        }

        if ($delete($id) !== true) {
            return Response::error(404, 'not_found', 'Not Found');
        }

        return Response::noContent();
    }
}
