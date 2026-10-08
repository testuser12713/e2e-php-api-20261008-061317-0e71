<?php

declare(strict_types=1);

namespace App\Http;

/**
 * An HTTP response produced by the router and handlers.
 */
final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $status = 200,
        public array $headers = [],
        public string $body = '',
    ) {
    }

    /**
     * Build a JSON response.
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    public static function json(array $data, int $status = 200, array $headers = []): self
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            $body = '{}';
        }

        $response = new self($status, [], $body);
        $response->headers['Content-Type'] = 'application/json; charset=utf-8';
        foreach ($headers as $name => $value) {
            $response->headers[$name] = $value;
        }

        return $response;
    }

    /**
     * Build an empty 204 response.
     */
    public static function noContent(): self
    {
        return new self(204, [], '');
    }

    /**
     * Build the contract's JSON error response.
     *
     * @param array<string, mixed> $details
     */
    public static function error(int $status, string $code, string $message, array $details = []): self
    {
        return self::json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => (object) $details,
            ],
        ], $status);
    }

    /**
     * Emit the status line, headers and body to the client.
     */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }

        if ($this->body !== '') {
            echo $this->body;
        }
    }
}
