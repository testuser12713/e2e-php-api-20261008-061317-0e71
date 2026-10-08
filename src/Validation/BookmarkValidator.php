<?php

declare(strict_types=1);

namespace App\Validation;

/**
 * Bookmark input validation.
 *
 * The signature is fixed here so the feature tickets can build on it; the body
 * returns "no errors" until the validation ticket implements the rules.
 */
final class BookmarkValidator
{
    /**
     * @param array<string, mixed> $input
     *
     * @return list<array{field: string, message: string}>
     */
    public static function validate(array $input, bool $partial = false): array
    {
        return [];
    }
}
