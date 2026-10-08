<?php

declare(strict_types=1);

namespace App\Storage;

use PDO;

/**
 * PDO connection factory for the SQLite storage file.
 *
 * The path is read from DB_PATH on every call (default data/bookmarks.sqlite);
 * no connection is cached, so a changed environment or a fresh process always
 * sees the configured file. The parent directory and the schema are created on
 * first use, so a freshly started server works without any manual setup.
 */
final class Database
{
    private const DEFAULT_PATH = 'data/bookmarks.sqlite';

    public static function connection(): PDO
    {
        $path = getenv('DB_PATH');
        if ($path === false || trim($path) === '') {
            $path = self::DEFAULT_PATH;
        }

        $directory = dirname($path);
        if ($directory !== '' && $directory !== '.' && !is_dir($directory)) {
            if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new \RuntimeException('Unable to create the database directory: ' . $directory);
            }
        }

        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS bookmarks ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'url TEXT NOT NULL, '
            . 'title TEXT NOT NULL, '
            . 'tags TEXT NOT NULL, '
            . 'created_at TEXT NOT NULL, '
            . 'updated_at TEXT NOT NULL)'
        );

        return $pdo;
    }
}
