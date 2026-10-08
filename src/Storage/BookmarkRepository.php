<?php

declare(strict_types=1);

namespace App\Storage;

use PDO;

/**
 * Bookmark persistence backed by a PDO SQLite connection.
 *
 * All statements are prepared. Rows are returned as Bookmark arrays
 * {id, url, title, tags, created_at, updated_at}; tags are stored as a JSON
 * string and normalized (trimmed, lower-cased, case-insensitively deduplicated,
 * first-occurrence order) both when written and when read.
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
        $now = $this->now();
        $tags = $this->normalizeTags($data['tags'] ?? []);

        $statement = $this->pdo->prepare(
            'INSERT INTO bookmarks (url, title, tags, created_at, updated_at) '
            . 'VALUES (:url, :title, :tags, :created_at, :updated_at)'
        );
        $statement->execute([
            'url' => (string) ($data['url'] ?? ''),
            'title' => (string) ($data['title'] ?? ''),
            'tags' => json_encode($tags, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $bookmark = $this->findById($id);

        if ($bookmark === null) {
            throw new \RuntimeException('Failed to read the bookmark that was just created.');
        }

        return $bookmark;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAll(?string $tag): array
    {
        $statement = $this->pdo->query(
            'SELECT id, url, title, tags, created_at, updated_at FROM bookmarks '
            . 'ORDER BY created_at DESC, id DESC'
        );

        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            $bookmark = $this->hydrate($row);
            if ($tag !== null && !$this->hasTag($bookmark['tags'], $tag)) {
                continue;
            }
            $rows[] = $bookmark;
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, url, title, tags, created_at, updated_at FROM bookmarks WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return $this->hydrate($row);
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
        $tags = array_key_exists('tags', $data) ? $this->normalizeTags($data['tags']) : $current['tags'];

        $statement = $this->pdo->prepare(
            'UPDATE bookmarks SET url = :url, title = :title, tags = :tags, updated_at = :updated_at '
            . 'WHERE id = :id'
        );
        $statement->execute([
            'url' => $url,
            'title' => $title,
            'tags' => json_encode($tags, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'updated_at' => $this->now(),
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
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $decoded = json_decode((string) $row['tags'], true);
        $tags = is_array($decoded) ? $decoded : [];

        return [
            'id' => (int) $row['id'],
            'url' => (string) $row['url'],
            'title' => (string) $row['title'],
            'tags' => $this->normalizeTags($tags),
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * Trim, lower-case, drop case-insensitive duplicates and keep first-occurrence order.
     *
     * @param mixed $tags
     *
     * @return list<string>
     */
    private function normalizeTags(mixed $tags): array
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
            $tag = mb_strtolower(trim($tag));
            if ($tag === '' || isset($seen[$tag])) {
                continue;
            }
            $seen[$tag] = true;
            $normalized[] = $tag;
        }

        return $normalized;
    }

    /**
     * Exact, case-insensitive tag match — never a substring.
     *
     * @param list<string> $tags
     */
    private function hasTag(array $tags, string $tag): bool
    {
        $needle = mb_strtolower(trim($tag));
        if ($needle === '') {
            return false;
        }

        foreach ($tags as $candidate) {
            if (mb_strtolower($candidate) === $needle) {
                return true;
            }
        }

        return false;
    }

    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
