<?php

declare(strict_types=1);

namespace App\Storage;

use PDO;

/**
 * Shared PDO connection factory.
 *
 * The SQLite file location follows the DB_PATH environment variable and is read
 * on every call (no static caching) so tests can point each test at its own
 * fresh temporary file. The parent directory and the schema are created when
 * they are missing, so a freshly started server works without any manual setup.
 */
final class Database
{
    public const DEFAULT_PATH = 'data/bookmarks.sqlite';

    public static function connection(): PDO
    {
        $path = getenv('DB_PATH');
        if ($path === false || $path === '') {
            $path = self::DEFAULT_PATH;
        }

        $directory = dirname($path);
        if ($directory !== '' && $directory !== '.' && !is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS bookmarks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                url TEXT NOT NULL,
                title TEXT NOT NULL,
                tags TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );

        return $pdo;
    }
}
