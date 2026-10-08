<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

/**
 * Raised when a request body is missing or cannot be parsed as JSON.
 *
 * The front controller maps this exception to a 400 bad_request response.
 */
final class JsonException extends RuntimeException
{
}
