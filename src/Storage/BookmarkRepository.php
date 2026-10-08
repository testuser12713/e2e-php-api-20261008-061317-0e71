<?php

declare(strict_types=1);

namespace App\Storage;

use PDO;

/**
 * Bookmark persistence backed by a single SQLite table.
 *
 * Every query uses a prepared statement. Tags are stored as a JSON array and
 * are normalized (trimmed, lower-cased, case-insensitively deduplicated,
 * first occurrence kept) both when writing and when reading, so a Bookmark
 * array always carries normalized tags.
 *
 * @phpstan-type Bookmark array{
 *     id: int,
 *     url: string,
 *     title: string,
 *     tags: list<string>,
 *     created_at: string,
 *     updated_at: string
 * }
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
        $url = (string) ($data['url'] ?? '');
        $title = (string) ($data['title'] ?? '');
        $tags = $this->normalizeTags($data['tags'] ?? []);
        $now = $this->now();

        $statement = $this->pdo->prepare(
            'INSERT INTO bookmarks (url, title, tags, created_at, updated_at) VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $url,
            $title,
            $this->encodeTags($tags),
            $now,
            $now,
        ]);

        return [
            'id' => (int) $this->pdo->lastInsertId(),
            'url' => $url,
            'title' => $title,
            'tags' => $tags,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAll(?string $tag): array
    {
        $statement = $this->pdo->query('SELECT * FROM bookmarks ORDER BY created_at DESC, id DESC');
        $rows = $statement === false ? [] : $statement->fetchAll();

        $bookmarks = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $bookmarks[] = $this->hydrate($row);
        }

        if ($tag === null) {
            return $bookmarks;
        }

        $needle = $this->normalizeTag($tag);
        if ($needle === '') {
            return $bookmarks;
        }

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
        $statement = $this->pdo->prepare('SELECT * FROM bookmarks WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();

        if ($row === false || !is_array($row)) {
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
        $existing = $this->findById($id);
        if ($existing === null) {
            return null;
        }

        $url = array_key_exists('url', $data) ? (string) $data['url'] : $existing['url'];
        $title = array_key_exists('title', $data) ? (string) $data['title'] : $existing['title'];
        $tags = array_key_exists('tags', $data)
            ? $this->normalizeTags($data['tags'])
            : $existing['tags'];

        $now = $this->now();

        $statement = $this->pdo->prepare(
            'UPDATE bookmarks SET url = ?, title = ?, tags = ?, updated_at = ? WHERE id = ?'
        );
        $statement->execute([$url, $title, $this->encodeTags($tags), $now, $id]);

        return [
            'id' => $id,
            'url' => $url,
            'title' => $title,
            'tags' => $tags,
            'created_at' => $existing['created_at'],
            'updated_at' => $now,
        ];
    }

    public function delete(int $id): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM bookmarks WHERE id = ?');
        $statement->execute([$id]);

        return $statement->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'url' => (string) ($row['url'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'tags' => $this->normalizeTags(json_decode((string) ($row['tags'] ?? '[]'), true)),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * Normalize a tag list: trim, lower-case, drop empties, dedupe
     * case-insensitively while keeping first-occurrence order.
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
            $value = $this->normalizeTag($tag);
            if ($value === '' || isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $normalized[] = $value;
        }

        return $normalized;
    }

    private function normalizeTag(string $tag): string
    {
        return strtolower(trim($tag));
    }

    /**
     * @param list<string> $tags
     */
    private function encodeTags(array $tags): string
    {
        $encoded = json_encode($tags, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '[]' : $encoded;
    }

    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
