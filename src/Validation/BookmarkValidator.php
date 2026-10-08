<?php

declare(strict_types=1);

namespace App\Validation;

/**
 * Bookmark input validation.
 *
 * Returns an empty array when the input is valid, otherwise one
 * {field, message} entry per problem. No normalization happens here — that is
 * the storage layer's job. When $partial is true a MISSING field is tolerated
 * (for updates), but an invalid value never is.
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

        if ($partial && !array_key_exists('url', $input)) {
            // Missing on a partial update — nothing to check.
        } else {
            $url = $input['url'] ?? null;
            if (!is_string($url) || !self::isHttpUrl($url)) {
                $errors[] = [
                    'field' => 'url',
                    'message' => 'The url must be a valid http(s) URL.',
                ];
            }
        }

        if ($partial && !array_key_exists('title', $input)) {
            // Missing on a partial update — nothing to check.
        } else {
            $title = $input['title'] ?? null;
            if (!is_string($title) || trim($title) === '') {
                $errors[] = [
                    'field' => 'title',
                    'message' => 'The title must not be empty.',
                ];
            }
        }

        if (array_key_exists('tags', $input)) {
            $tags = $input['tags'];
            if (!is_array($tags) || !array_is_list($tags) || self::containsNonString($tags)) {
                $errors[] = [
                    'field' => 'tags',
                    'message' => 'The tags must be a list of strings.',
                ];
            }
        }

        return $errors;
    }

    private static function isHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return ($scheme === 'http' || $scheme === 'https')
            && is_string(parse_url($url, PHP_URL_HOST))
            && parse_url($url, PHP_URL_HOST) !== '';
    }

    /**
     * @param list<mixed> $tags
     */
    private static function containsNonString(array $tags): bool
    {
        foreach ($tags as $tag) {
            if (!is_string($tag)) {
                return true;
            }
        }

        return false;
    }
}
