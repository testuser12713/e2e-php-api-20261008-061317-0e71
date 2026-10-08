<?php

declare(strict_types=1);

namespace App\Storage;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Bookmark persistence over PDO/SQLite.
 */
final class BookmarkRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $now = self::now();
        $tags = self::normalizeTags($data['tags'] ?? []);

        $statement = $this->pdo->prepare(
            'INSERT INTO bookmarks (url, title, tags, created_at, updated_at) '
            . 'VALUES (:url, :title, :tags, :created_at, :updated_at)'
        );
        $statement->execute([
            ':url' => (string) ($data['url'] ?? ''),
            ':title' => (string) ($data['title'] ?? ''),
            ':tags' => self::encodeTags($tags),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId()) ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAll(?string $tag): array
    {
        $statement = $this->pdo->query('SELECT * FROM bookmarks ORDER BY created_at DESC, id DESC');

        $bookmarks = [];
        foreach ($statement->fetchAll() as $row) {
            $bookmarks[] = self::hydrate($row);
        }

        if ($tag === null) {
            return $bookmarks;
        }

        $needle = strtolower(trim($tag));

        return array_values(array_filter(
            $bookmarks,
            static fn (array $bookmark): bool => in_array($needle, $bookmark['tags'], true)
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM bookmarks WHERE id = :id');
        $statement->execute([':id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : self::hydrate($row);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>|null
     */
    public function update(int $id, array $data): ?array
    {
        if ($this->findById($id) === null) {
            return null;
        }

        $assignments = [];
        $parameters = [':id' => $id];

        if (array_key_exists('url', $data)) {
            $assignments[] = 'url = :url';
            $parameters[':url'] = (string) $data['url'];
        }
        if (array_key_exists('title', $data)) {
            $assignments[] = 'title = :title';
            $parameters[':title'] = (string) $data['title'];
        }
        if (array_key_exists('tags', $data)) {
            $assignments[] = 'tags = :tags';
            $parameters[':tags'] = self::encodeTags(self::normalizeTags($data['tags']));
        }

        $assignments[] = 'updated_at = :updated_at';
        $parameters[':updated_at'] = self::now();

        $statement = $this->pdo->prepare(
            'UPDATE bookmarks SET ' . implode(', ', $assignments) . ' WHERE id = :id'
        );
        $statement->execute($parameters);

        return $this->findById($id);
    }

    public function delete(int $id): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM bookmarks WHERE id = :id');
        $statement->execute([':id' => $id]);

        return $statement->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'url' => (string) $row['url'],
            'title' => (string) $row['title'],
            'tags' => self::normalizeTags(self::decodeTags((string) $row['tags'])),
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * Trim, lower-case and case-insensitively deduplicate, keeping first occurrence.
     *
     * @return list<string>
     */
    private static function normalizeTags(mixed $tags): array
    {
        if (!is_array($tags)) {
            return [];
        }

        $normalized = [];
        $seen = [];
        foreach ($tags as $tag) {
            if (!is_string($tag)) {
                continue;
            }

            $value = strtolower(trim($tag));
            if ($value === '' || isset($seen[$value])) {
                continue;
            }

            $seen[$value] = true;
            $normalized[] = $value;
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private static function decodeTags(string $raw): array
    {
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param list<string> $tags
     */
    private static function encodeTags(array $tags): string
    {
        $encoded = json_encode($tags, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '[]' : $encoded;
    }

    private static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
