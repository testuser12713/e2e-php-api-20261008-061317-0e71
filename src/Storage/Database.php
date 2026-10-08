<?php

declare(strict_types=1);

namespace App\Storage;

use PDO;

/**
 * SQLite connection factory.
 *
 * The database path is read from the DB_PATH environment variable on every
 * call (default: data/bookmarks.sqlite) and is deliberately NOT cached, so the
 * tests can point DB_PATH at a temporary file between calls. The schema is
 * created automatically the first time the file is opened.
 */
final class Database
{
    private const DEFAULT_PATH = 'data/bookmarks.sqlite';

    public static function connection(): PDO
    {
        $path = self::path();

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

    private static function path(): string
    {
        $path = getenv('DB_PATH');
        if ($path === false || trim($path) === '') {
            return self::DEFAULT_PATH;
        }

        return $path;
    }
}
