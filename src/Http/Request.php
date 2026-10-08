<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Immutable view of an incoming HTTP request.
 */
final class Request
{
    /**
     * @param array<string, mixed>  $query
     * @param array<string, string> $headers lower-case header keys
     */
    public function __construct(
        public string $method = 'GET',
        public string $path = '/',
        public string $body = '',
        public array $query = [],
        public array $headers = [],
    ) {
    }

    /**
     * Build a request from the PHP superglobals.
     */
    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }

        $body = file_get_contents('php://input');
        if ($body === false) {
            $body = '';
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $key => $name) {
            if (isset($_SERVER[$key])) {
                $headers[$name] = (string) $_SERVER[$key];
            }
        }

        $query = $_GET ?? [];
        if (!is_array($query)) {
            $query = [];
        }

        return new self($method, $path, (string) $body, $query, $headers);
    }

    /**
     * Decode the request body as a JSON object.
     *
     * @return array<string, mixed>
     *
     * @throws JsonException when the body is empty or not valid JSON.
     */
    public function json(): array
    {
        if (trim($this->body) === '') {
            throw new JsonException('Request body is empty');
        }

        $decoded = json_decode($this->body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new JsonException('Request body is not valid JSON');
        }

        if (!is_array($decoded)) {
            throw new JsonException('Request body must be a JSON object');
        }

        return $decoded;
    }
}
