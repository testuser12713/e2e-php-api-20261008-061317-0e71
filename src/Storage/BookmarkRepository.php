<?php

declare(strict_types=1);

namespace App\Storage;

use PDO;

/**
 * Bookmark persistence.
 *
 * Tags are stored as a JSON string and normalized (trimmed, lower-cased,
 * case-insensitively deduplicated, first-occurrence order) on both write and
 * read. Every statement is prepared.
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

        $statement = $this->pdo->prepare(
            'INSERT INTO bookmarks (url, title, tags, created_at, updated_at) '
            . 'VALUES (:url, :title, :tags, :created_at, :updated_at)'
        );
        $statement->execute([
            ':url' => (string) ($data['url'] ?? ''),
            ':title' => (string) ($data['title'] ?? ''),
            ':tags' => $this->encodeTags($data['tags'] ?? []),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        $id = (int) $this->pdo->lastInsertId();

        return $this->findById($id) ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAll(?string $tag): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM bookmarks ORDER BY created_at DESC, id DESC'
        );
        $statement->execute();

        $bookmarks = [];
        foreach ($statement->fetchAll() as $row) {
            $bookmark = $this->hydrate($row);
            if ($tag !== null) {
                $needle = $this->normalizeTag($tag);
                if (!in_array($needle, $bookmark['tags'], true)) {
                    continue;
                }
            }
            $bookmarks[] = $bookmark;
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

        return is_array($row) ? $this->hydrate($row) : null;
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

        $statement = $this->pdo->prepare(
            'UPDATE bookmarks SET url = :url, title = :title, tags = :tags, updated_at = :updated_at '
            . 'WHERE id = :id'
        );
        $statement->execute([
            ':url' => $url,
            ':title' => $title,
            ':tags' => $this->encodeTags($tags),
            ':updated_at' => $this->now(),
            ':id' => $id,
        ]);

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
    private function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'url' => (string) $row['url'],
            'title' => (string) $row['title'],
            'tags' => $this->normalizeTags(json_decode((string) $row['tags'], true)),
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
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
            $tag = $this->normalizeTag($tag);
            if ($tag === '' || isset($seen[$tag])) {
                continue;
            }
            $seen[$tag] = true;
            $normalized[] = $tag;
        }

        return $normalized;
    }

    private function normalizeTag(string $tag): string
    {
        $tag = trim($tag);

        return function_exists('mb_strtolower') ? mb_strtolower($tag) : strtolower($tag);
    }

    /**
     * @param list<string> $tags
     */
    private function encodeTags(array $tags): string
    {
        $encoded = json_encode(
            array_values($tags),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return $encoded === false ? '[]' : $encoded;
    }

    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
