<?php

declare(strict_types=1);

namespace App\Validation;

/**
 * Bookmark input validation.
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
        } elseif (!self::isHttpUrl($input['url'])) {
            $errors[] = ['field' => 'url', 'message' => 'url must be a valid http(s) URL'];
        }

        if (!array_key_exists('title', $input)) {
            if (!$partial) {
                $errors[] = ['field' => 'title', 'message' => 'title is required'];
            }
        } elseif (!is_string($input['title']) || trim($input['title']) === '') {
            $errors[] = ['field' => 'title', 'message' => 'title must not be empty'];
        }

        if (array_key_exists('tags', $input) && !self::isTagList($input['tags'])) {
            $errors[] = ['field' => 'tags', 'message' => 'tags must be a list of strings'];
        }

        return $errors;
    }

    private static function isHttpUrl(mixed $url): bool
    {
        if (!is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return preg_match('#^https?://#i', $url) === 1;
    }

    private static function isTagList(mixed $tags): bool
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
