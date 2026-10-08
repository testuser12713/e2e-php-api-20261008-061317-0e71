<?php

declare(strict_types=1);

namespace App\Storage;

use PDO;
use RuntimeException;

/**
 * PDO connection factory for the SQLite bookmark store.
 *
 * DB_PATH is read on every call so a test can point it at a fresh temporary
 * file, and no connection is cached between calls.
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
            if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new RuntimeException(sprintf('Unable to create database directory "%s"', $directory));
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
