<?php

declare(strict_types=1);

namespace App\Validation;

/**
 * Validates bookmark input. Returns an empty list when valid, otherwise one
 * entry per problem. It never normalizes values.
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

        foreach (['url', 'title'] as $field) {
            if (!array_key_exists($field, $input)) {
                if (!$partial) {
                    $errors[] = ['field' => $field, 'message' => sprintf('%s is required', $field)];
                }
                continue;
            }

            $value = $input[$field];
            if (!is_string($value) || trim($value) === '') {
                $errors[] = ['field' => $field, 'message' => sprintf('%s is required', $field)];
                continue;
            }

            if ($field === 'url' && !self::isHttpUrl($value)) {
                $errors[] = ['field' => 'url', 'message' => 'url must be a valid http(s) URL'];
            }
        }

        if (array_key_exists('tags', $input)) {
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

    private static function isHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return $scheme === 'http' || $scheme === 'https';
    }
}
