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
 * in partial mode. Invalid values answer 422 with the offending field, an
 * unknown id answers 404, otherwise the updated resource is returned with 200.
 *
 * The collaborators default to the real BookmarkValidator and a
 * BookmarkRepository on the shared Database connection. The optional callables
 * exist purely as a test seam so this handler's status/body mapping can be
 * exercised without the storage layer.
 */
final class UpdateBookmarkHandler
{
    /**
     * @var callable(array<string, mixed>, bool): list<array{field: string, message: string}>
     */
    private $validator;

    /**
     * @var callable(): BookmarkRepository
     */
    private $repositoryFactory;

    /**
     * @param callable(array<string, mixed>, bool): list<array{field: string, message: string}>|null $validator
     * @param callable(): BookmarkRepository|null                                                     $repositoryFactory
     */
    public function __construct(?callable $validator = null, ?callable $repositoryFactory = null)
    {
        $this->validator = $validator
            ?? static fn (array $input, bool $partial): array => BookmarkValidator::validate($input, $partial);

        $this->repositoryFactory = $repositoryFactory
            ?? static fn (): BookmarkRepository => new BookmarkRepository(Database::connection());
    }

    public function __invoke(Request $request, array $params): Response
    {
        $input = $request->json();

        $errors = ($this->validator)($input, true);
        if ($errors !== []) {
            return Response::error(
                422,
                'validation_failed',
                'Validation Failed',
                ['field' => $errors[0]['field']]
            );
        }

        $id = (int) ($params['id'] ?? 0);
        $bookmark = ($this->repositoryFactory)()->update($id, $input);

        if ($bookmark === null) {
            return Response::error(404, 'not_found', 'Not Found');
        }

        return Response::json($bookmark);
    }
}
