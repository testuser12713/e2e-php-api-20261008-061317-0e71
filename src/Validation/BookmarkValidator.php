<?php

declare(strict_types=1);

namespace App\Validation;

/**
 * Bookmark input validation.
 *
 * Returns a list of field errors (empty when the input is valid). The create
 * request requires url and title; $partial = true skips the missing-field check
 * for PUT/PATCH while still validating any field that is present.
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
            if (!is_string($url) || trim($url) === '') {
                $errors[] = ['field' => 'url', 'message' => 'url must be a non-empty string'];
            } elseif (!self::isValidUrl($url)) {
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
                $errors[] = ['field' => 'title', 'message' => 'title must be a non-empty string'];
            }
        }

        if (array_key_exists('tags', $input) && $input['tags'] !== null) {
            $tags = $input['tags'];
            if (!is_array($tags) || !array_is_list($tags)) {
                $errors[] = ['field' => 'tags', 'message' => 'tags must be a list of strings'];
            } else {
                foreach ($tags as $tag) {
                    if (!is_string($tag)) {
                        $errors[] = ['field' => 'tags', 'message' => 'tags must be a list of strings'];
                        break;
                    }
                }
            }
        }

        return $errors;
    }

    private static function isValidUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true);
    }
}
