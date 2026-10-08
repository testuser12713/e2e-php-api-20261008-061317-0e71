<?php

declare(strict_types=1);

namespace App\Validation;

/**
 * Bookmark input validation.
 *
 * Returns an empty list when the input is valid, otherwise one
 * {field, message} entry per problem. With $partial = true a MISSING field is
 * tolerated (PUT/PATCH), an invalid one never is. No normalization happens
 * here; that lives in the repository.
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
        $errors = [];

        if (!array_key_exists('url', $input)) {
            if (!$partial) {
                $errors[] = ['field' => 'url', 'message' => 'url is required'];
            }
        } else {
            $url = $input['url'];
            if (!is_string($url) || !self::isHttpUrl($url)) {
                $errors[] = ['field' => 'url', 'message' => 'url must be a valid http(s) URL'];
            }
        }

        if (!array_key_exists('title', $input)) {
            if (!$partial) {
                $errors[] = ['field' => 'title', 'message' => 'title is required'];
            }
        } else {
            $title = $input['title'];
            if (!is_string($title) || trim($title) === '') {
                $errors[] = ['field' => 'title', 'message' => 'title must not be empty'];
            }
        }

        if (array_key_exists('tags', $input) && !self::isStringList($input['tags'])) {
            $errors[] = ['field' => 'tags', 'message' => 'tags must be a list of strings'];
        }

        return $errors;
    }

    private static function isHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return $scheme === 'http' || $scheme === 'https';
    }

    private static function isStringList(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (!is_string($item)) {
                return false;
            }
        }

        return true;
    }
}
