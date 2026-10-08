<?php

declare(strict_types=1);

namespace App\Handler;

use App\Http\Request;
use App\Http\Response;
use App\Storage\BookmarkRepository;
use App\Storage\Database;
use Closure;

/**
 * GET /api/bookmarks/{id} — returns a single bookmark or a 404 JSON error.
 *
 * The optional finder is a seam for the sparse test double: production builds
 * the shared storage stack and calls BookmarkRepository::findById(), while the
 * unit test injects a finder so the 200/404 branches are covered without a
 * database (BookmarkRepository is final and cannot be doubled by PHPUnit).
 */
final class ShowBookmarkHandler
{
    /**
     * @param (Closure(int): (array<string, mixed>|null))|null $finder
     */
    public function __construct(private ?Closure $finder = null)
    {
    }

    public function __invoke(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $bookmark = $this->finder !== null
            ? ($this->finder)($id)
            : (new BookmarkRepository(Database::connection()))->findById($id);

        if ($bookmark === null) {
            return Response::error(404, 'not_found', 'Not Found');
        }

        return Response::json($bookmark);
    }
}
