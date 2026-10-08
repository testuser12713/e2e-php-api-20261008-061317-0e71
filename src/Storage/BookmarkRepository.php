<?php

declare(strict_types=1);

namespace App\Storage;

use PDO;

/**
 * Bookmark persistence on top of SQLite.
 *
 * Tags are stored as a JSON array of normalized strings (trimmed, lower-cased,
 * case-insensitively deduplicated, first-occurrence order).
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
            'INSERT INTO bookmarks (url, title, tags, created_at, updated_at)
             VALUES (:url, :title, :tags, :created_at, :updated_at)'
        );
        $statement->execute([
            ':url' => (string) ($data['url'] ?? ''),
            ':title' => (string) ($data['title'] ?? ''),
            ':tags' => self::encodeTags($tags),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        $bookmark = $this->findById((int) $this->pdo->lastInsertId());

        return $bookmark ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAll(?string $tag): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM bookmarks ORDER BY created_at DESC, id DESC');
        $statement->execute();

        $needle = $tag === null ? null : self::normalizeTag($tag);

        $bookmarks = [];
        foreach ($statement->fetchAll() as $row) {
            $bookmark = self::hydrate($row);
            if ($needle === null || in_array($needle, $bookmark['tags'], true)) {
                $bookmarks[] = $bookmark;
            }
        }

        return $bookmarks;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM bookmarks WHERE id = :id');
        $statement->execute([':id' => $id]);

        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return self::hydrate($row);
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
        $params = [':id' => $id];

        if (array_key_exists('url', $data)) {
            $assignments[] = 'url = :url';
            $params[':url'] = (string) $data['url'];
        }
        if (array_key_exists('title', $data)) {
            $assignments[] = 'title = :title';
            $params[':title'] = (string) $data['title'];
        }
        if (array_key_exists('tags', $data)) {
            $assignments[] = 'tags = :tags';
            $params[':tags'] = self::encodeTags(self::normalizeTags($data['tags']));
        }

        $assignments[] = 'updated_at = :updated_at';
        $params[':updated_at'] = self::now();

        $statement = $this->pdo->prepare(
            'UPDATE bookmarks SET ' . implode(', ', $assignments) . ' WHERE id = :id'
        );
        $statement->execute($params);

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
        $decoded = json_decode((string) ($row['tags'] ?? '[]'), true);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'url' => (string) ($row['url'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'tags' => array_values(array_map('strval', $decoded)),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * @param mixed $tags
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

            $value = self::normalizeTag($tag);
            if ($value === '' || isset($seen[$value])) {
                continue;
            }

            $seen[$value] = true;
            $normalized[] = $value;
        }

        return $normalized;
    }

    private static function normalizeTag(string $tag): string
    {
        $trimmed = trim($tag);

        return function_exists('mb_strtolower') ? mb_strtolower($trimmed, 'UTF-8') : strtolower($trimmed);
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
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }
}
