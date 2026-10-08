<?php

declare(strict_types=1);

namespace App\Storage;

use PDO;

/**
 * Bookmark persistence over PDO/SQLite.
 *
 * Rows are returned as {id:int, url:string, title:string, tags:string[],
 * created_at:string, updated_at:string} with ISO-8601 UTC timestamps. Tags are
 * stored as JSON and normalized (trim, lower-case, case-insensitive dedup,
 * first-occurrence order) on every write and read.
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
            ':url' => (string) ($data['url'] ?? ''),
            ':title' => (string) ($data['title'] ?? ''),
            ':tags' => $this->encodeTags($tags),
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
        $statement = $this->pdo->query(
            'SELECT id, url, title, tags, created_at, updated_at '
            . 'FROM bookmarks ORDER BY created_at DESC, id DESC'
        );

        $bookmarks = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $bookmarks[] = $this->hydrate($row);
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
        $statement = $this->pdo->prepare(
            'SELECT id, url, title, tags, created_at, updated_at FROM bookmarks WHERE id = :id'
        );
        $statement->execute([':id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
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
        $decoded = json_decode((string) $row['tags'], true);

        return [
            'id' => (int) $row['id'],
            'url' => (string) $row['url'],
            'title' => (string) $row['title'],
            'tags' => $this->normalizeTags($decoded),
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
