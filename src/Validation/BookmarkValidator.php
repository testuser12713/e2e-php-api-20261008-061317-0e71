<?php

declare(strict_types=1);

namespace App\Validation;

/**
 * Bookmark input validation.
 *
 * Returns an empty list when the input is valid, otherwise one entry per
 * problem as {field, message}. No normalization happens here: trimming and
 * tag normalization belong to the storage layer.
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

        if (!$partial || array_key_exists('url', $input)) {
            $errors = array_merge($errors, self::validateUrl($input));
        }

        if (!$partial || array_key_exists('title', $input)) {
            $errors = array_merge($errors, self::validateTitle($input));
        }

        if (array_key_exists('tags', $input)) {
            $errors = array_merge($errors, self::validateTags($input));
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return list<array{field: string, message: string}>
     */
    private static function validateUrl(array $input): array
    {
        if (!array_key_exists('url', $input)) {
            return [['field' => 'url', 'message' => 'url is required']];
        }

        $url = $input['url'];
        if (!is_string($url) || trim($url) === '') {
            return [['field' => 'url', 'message' => 'url is required and must be a valid http(s) URL']];
        }

        $url = trim($url);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (filter_var($url, FILTER_VALIDATE_URL) === false || ($scheme !== 'http' && $scheme !== 'https')) {
            return [['field' => 'url', 'message' => 'url must be a valid http(s) URL']];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return list<array{field: string, message: string}>
     */
    private static function validateTitle(array $input): array
    {
        if (!array_key_exists('title', $input)) {
            return [['field' => 'title', 'message' => 'title is required']];
        }

        $title = $input['title'];
        if (!is_string($title) || trim($title) === '') {
            return [['field' => 'title', 'message' => 'title is required and must not be empty']];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return list<array{field: string, message: string}>
     */
    private static function validateTags(array $input): array
    {
        $tags = $input['tags'];

        if (!is_array($tags) || !array_is_list($tags)) {
            return [['field' => 'tags', 'message' => 'tags must be a list of strings']];
        }

        foreach ($tags as $tag) {
            if (!is_string($tag)) {
                return [['field' => 'tags', 'message' => 'tags must be a list of strings']];
            }
        }

        return [];
    }
}
