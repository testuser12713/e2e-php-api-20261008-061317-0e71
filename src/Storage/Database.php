<?php

declare(strict_types=1);

namespace App\Storage;

use PDO;

/**
 * Shared PDO connection factory.
 *
 * The database path is read from DB_PATH on every call so tests can point it
 * at a fresh temporary file per test; the connection is never cached. The
 * parent directory and the schema are created on demand, so a freshly started
 * server works without any manual setup.
 */
final class Database
{
    private const DEFAULT_PATH = 'data/bookmarks.sqlite';

    public static function connection(): PDO
    {
        $path = getenv('DB_PATH');
        if (!is_string($path) || $path === '') {
            $path = self::DEFAULT_PATH;
        }

        if ($path !== ':memory:') {
            $directory = dirname($path);
            if ($directory !== '' && $directory !== '.' && !is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
        }

        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS bookmarks ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
            . 'url TEXT NOT NULL,'
            . 'title TEXT NOT NULL,'
            . 'tags TEXT NOT NULL,'
            . 'created_at TEXT NOT NULL,'
            . 'updated_at TEXT NOT NULL'
            . ')'
        );

        return $pdo;
    }
}
