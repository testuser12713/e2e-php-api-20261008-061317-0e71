<?php

declare(strict_types=1);

namespace App\Handler;

use App\Http\Request;
use App\Http\Response;
use App\Storage\BookmarkRepository;
use App\Storage\Database;
use App\Validation\BookmarkValidator;

/**
 * PUT|PATCH /api/bookmarks/{id}
 *
 * Applies the supplied subset of {url, title, tags} to an existing bookmark.
 * The fields are validated partially — only the supplied ones — and invalid
 * values answer 422 with the offending field in the error details. An unknown id
 * answers 404; otherwise the updated resource is returned with status 200.
 */
final class UpdateBookmarkHandler
{
    /** @var callable(int, array<string, mixed>): (array<string, mixed>|null) */
    private $update;

    /** @var callable(array<string, mixed>, bool): list<array{field: string, message: string}> */
    private $validate;

    /**
     * The dependencies default to the real repository and validator. They are
     * injected in tests so the handler can be exercised without a live database;
     * they are resolved lazily, so configuration is only read when a request
     * actually needs it.
     *
     * @param callable(int, array<string, mixed>): (array<string, mixed>|null)|null $update
     * @param callable(array<string, mixed>, bool): list<array{field: string, message: string}>|null $validate
     */
    public function __construct(?callable $update = null, ?callable $validate = null)
    {
        $this->update = $update ?? static function (int $id, array $data): ?array {
            return (new BookmarkRepository(Database::connection()))->update($id, $data);
        };
        $this->validate = $validate ?? static function (array $input, bool $partial): array {
            return BookmarkValidator::validate($input, $partial);
        };
    }

    public function __invoke(Request $request, array $params): Response
    {
        $input = $request->json();

        $errors = ($this->validate)($input, true);
        if ($errors !== []) {
            $first = $errors[0];

            return Response::error(422, 'validation_failed', $first['message'], ['field' => $first['field']]);
        }

        $id = (int) ($params['id'] ?? 0);

        $bookmark = ($this->update)($id, $input);
        if ($bookmark === null) {
            return Response::error(404, 'not_found', 'Bookmark not found');
        }

        return Response::json($bookmark);
    }
}
