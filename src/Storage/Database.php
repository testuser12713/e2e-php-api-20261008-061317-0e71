<?php

declare(strict_types=1);

namespace App\Storage;

use PDO;
use RuntimeException;

/**
 * Shared PDO connection factory.
 *
 * The database file is a SQLite file at DB_PATH (default data/bookmarks.sqlite).
 * The environment variable is read on every call so tests can point DB_PATH at a
 * temporary file; the connection is never cached statically.
 */
final class Database
{
    private const DEFAULT_PATH = 'data/bookmarks.sqlite';

    public static function connection(): PDO
    {
        $path = getenv('DB_PATH');
        if ($path === false || $path === '') {
            $path = self::DEFAULT_PATH;
        }

        self::ensureDirectory(dirname($path));

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

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

    private static function ensureDirectory(string $directory): void
    {
        if ($directory === '' || $directory === '.' || is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create database directory "%s"', $directory));
        }
    }
}
