<?php

declare(strict_types=1);

namespace App\Validation;

/**
 * Bookmark input validation.
 *
 * Returns an empty list when the input is valid, otherwise one entry per
 * problem in the shape {field, message}. No normalization happens here; that
 * lives in the repository. With $partial = true (PUT/PATCH) a missing field is
 * acceptable, but a present yet invalid one is not.
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
                $errors[] = ['field' => 'url', 'message' => 'The url field is required.'];
            }
        } elseif (!self::isValidHttpUrl($input['url'])) {
            $errors[] = ['field' => 'url', 'message' => 'The url field must be a valid http(s) URL.'];
        }

        if (!array_key_exists('title', $input)) {
            if (!$partial) {
                $errors[] = ['field' => 'title', 'message' => 'The title field is required.'];
            }
        } elseif (!is_string($input['title']) || trim($input['title']) === '') {
            $errors[] = ['field' => 'title', 'message' => 'The title field must not be empty.'];
        }

        if (array_key_exists('tags', $input)) {
            $tags = $input['tags'];
            if (!is_array($tags) || !array_is_list($tags)) {
                $errors[] = ['field' => 'tags', 'message' => 'The tags field must be a list of strings.'];
            } else {
                foreach ($tags as $tag) {
                    if (!is_string($tag)) {
                        $errors[] = ['field' => 'tags', 'message' => 'The tags field must be a list of strings.'];
                        break;
                    }
                }
            }
        }

        return $errors;
    }

    private static function isValidHttpUrl(mixed $url): bool
    {
        if (!is_string($url)) {
            return false;
        }

        $url = trim($url);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true);
    }
}
