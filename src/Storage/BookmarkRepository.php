<?php

declare(strict_types=1);

namespace App\Storage;

use PDO;

/**
 * Bookmark persistence.
 *
 * Tags are stored as a JSON string and normalized (trim, lower-case,
 * case-insensitive deduplication, first-occurrence order) both on write and on
 * read, so the shape returned here is always the normalized Bookmark array.
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

        $statement = $this->pdo->prepare(
            'INSERT INTO bookmarks (url, title, tags, created_at, updated_at)
             VALUES (:url, :title, :tags, :created_at, :updated_at)'
        );
        $statement->execute([
            'url' => (string) ($data['url'] ?? ''),
            'title' => (string) ($data['title'] ?? ''),
            'tags' => self::encodeTags($data['tags'] ?? []),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id = (int) $this->pdo->lastInsertId();

        return $this->findById($id) ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAll(?string $tag): array
    {
        $statement = $this->pdo->query(
            'SELECT id, url, title, tags, created_at, updated_at
             FROM bookmarks
             ORDER BY created_at DESC, id DESC'
        );

        $bookmarks = [];
        foreach ($statement->fetchAll() as $row) {
            $bookmarks[] = self::mapRow($row);
        }

        if ($tag !== null) {
            $needle = strtolower(trim($tag));
            $bookmarks = array_values(array_filter(
                $bookmarks,
                static fn (array $bookmark): bool => in_array($needle, $bookmark['tags'], true)
            ));
        }

        return $bookmarks;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, url, title, tags, created_at, updated_at
             FROM bookmarks
             WHERE id = :id'
        );
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return self::mapRow($row);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>|null
     */
    public function update(int $id, array $data): ?array
    {
        $current = $this->findById($id);
        if ($current === null) {
            return null;
        }

        $url = array_key_exists('url', $data) ? (string) $data['url'] : $current['url'];
        $title = array_key_exists('title', $data) ? (string) $data['title'] : $current['title'];
        $tags = array_key_exists('tags', $data)
            ? self::encodeTags($data['tags'])
            : self::encodeTags($current['tags']);

        $statement = $this->pdo->prepare(
            'UPDATE bookmarks
             SET url = :url, title = :title, tags = :tags, updated_at = :updated_at
             WHERE id = :id'
        );
        $statement->execute([
            'url' => $url,
            'title' => $title,
            'tags' => $tags,
            'updated_at' => self::now(),
            'id' => $id,
        ]);

        return $this->findById($id);
    }

    public function delete(int $id): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM bookmarks WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $statement->rowCount() > 0;
    }

    /**
     * Encode a tag list as a normalized JSON string.
     *
     * @param mixed $tags
     */
    private static function encodeTags(mixed $tags): string
    {
        $encoded = json_encode(
            self::normalizeTags($tags),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return $encoded === false ? '[]' : $encoded;
    }

    /**
     * Normalize tags: trim, lower-case, case-insensitive dedup, keep order.
     *
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
        foreach ($tags as $tag) {
            if (!is_string($tag)) {
                continue;
            }

            $clean = strtolower(trim($tag));
            if ($clean === '') {
                continue;
            }

            $normalized[] = $clean;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function mapRow(array $row): array
    {
        $decoded = json_decode((string) ($row['tags'] ?? '[]'), true);

        return [
            'id' => (int) $row['id'],
            'url' => (string) $row['url'],
            'title' => (string) $row['title'],
            'tags' => self::normalizeTags($decoded),
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    private static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
