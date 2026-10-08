<?php

declare(strict_types=1);

namespace App\Validation;

/**
 * Bookmark input validation.
 *
 * Returns a list of {field, message} entries; an empty list means the input is
 * valid. When $partial is true (PUT/PATCH), absent fields are allowed — only
 * supplied values are checked.
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

        $hasUrl = array_key_exists('url', $input);
        if (!$partial || $hasUrl) {
            if (!$hasUrl) {
                $errors[] = ['field' => 'url', 'message' => 'url is required'];
            } elseif (!is_string($input['url']) || trim($input['url']) === '') {
                $errors[] = ['field' => 'url', 'message' => 'url must be a non-empty string'];
            } elseif (!self::isValidHttpUrl($input['url'])) {
                $errors[] = ['field' => 'url', 'message' => 'url must be a valid http(s) URL'];
            }
        }

        $hasTitle = array_key_exists('title', $input);
        if (!$partial || $hasTitle) {
            if (!$hasTitle) {
                $errors[] = ['field' => 'title', 'message' => 'title is required'];
            } elseif (!is_string($input['title']) || trim($input['title']) === '') {
                $errors[] = ['field' => 'title', 'message' => 'title must be a non-empty string'];
            }
        }

        if (array_key_exists('tags', $input) && !self::isValidTags($input['tags'])) {
            $errors[] = ['field' => 'tags', 'message' => 'tags must be a list of strings'];
        }

        return $errors;
    }

    private static function isValidHttpUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return $scheme === 'http' || $scheme === 'https';
    }

    private static function isValidTags(mixed $tags): bool
    {
        if (!is_array($tags) || !array_is_list($tags)) {
            return false;
        }

        foreach ($tags as $tag) {
            if (!is_string($tag)) {
                return false;
            }
        }

        return true;
    }
}
