<?php

declare(strict_types=1);

namespace App\Storage;

use PDO;
use RuntimeException;

/**
 * Shared PDO connection factory.
 *
 * The storage ticket fills in the real SQLite connection below DB_PATH; until
 * then the connection refuses with a RuntimeException that the front controller
 * turns into a JSON 500 response.
 */
final class Database
{
    public static function connection(): PDO
    {
        throw new RuntimeException('storage not implemented');
    }
}
