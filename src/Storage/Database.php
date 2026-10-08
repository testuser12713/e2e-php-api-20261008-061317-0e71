<?php

declare(strict_types=1);

namespace App\Storage;

use PDO;

/**
 * Shared PDO connection factory for the SQLite bookmark store.
 *
 * The database path is read from DB_PATH on every call (default
 * data/bookmarks.sqlite); nothing is cached, so tests can point DB_PATH at a
 * fresh temporary file. The schema is created when missing.
 */
final class Database
{
    public static function connection(): PDO
    {
        $path = getenv('DB_PATH');
        if ($path === false || $path === '') {
            $path = 'data/bookmarks.sqlite';
        }

        $directory = dirname($path);
        if ($directory !== '' && $directory !== '.' && !is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS bookmarks ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'url TEXT NOT NULL, '
            . 'title TEXT NOT NULL, '
            . 'tags TEXT NOT NULL, '
            . 'created_at TEXT NOT NULL, '
            . 'updated_at TEXT NOT NULL'
            . ')'
        );

        return $pdo;
    }
}
