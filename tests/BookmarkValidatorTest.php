<?php

declare(strict_types=1);

namespace App\Tests;

use App\Validation\BookmarkValidator;
use PHPUnit\Framework\TestCase;

final class BookmarkValidatorTest extends TestCase
{
    /**
     * @param list<array{field: string, message: string}> $errors
     *
     * @return list<string>
     */
    private function fields(array $errors): array
    {
        return array_map(static fn (array $error): string => $error['field'], $errors);
    }

    public function testValidInputPasses(): void
    {
        $errors = BookmarkValidator::validate([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['php', 'sql'],
        ]);

        $this->assertSame([], $errors);
    }

    public function testMissingUrlIsRejected(): void
    {
        $errors = BookmarkValidator::validate(['title' => 'Example']);

        $this->assertContains('url', $this->fields($errors));
    }

    public function testNonHttpUrlIsRejected(): void
    {
        foreach (['ftp://example.com', 'example.com', 'javascript:alert(1)', ''] as $url) {
            $errors = BookmarkValidator::validate(['url' => $url, 'title' => 'Example']);
            $this->assertContains('url', $this->fields($errors), 'url should be rejected: ' . $url);
        }
    }

    public function testMissingTitleIsRejected(): void
    {
        $errors = BookmarkValidator::validate(['url' => 'https://example.com']);

        $this->assertContains('title', $this->fields($errors));
    }

    public function testEmptyOrWhitespaceTitleIsRejected(): void
    {
        foreach (['', '   '] as $title) {
            $errors = BookmarkValidator::validate(['url' => 'https://example.com', 'title' => $title]);
            $this->assertContains('title', $this->fields($errors), 'title should be rejected: ' . var_export($title, true));
        }
    }

    public function testNonStringTagsValueIsRejected(): void
    {
        $errors = BookmarkValidator::validate([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => 'php',
        ]);

        $this->assertContains('tags', $this->fields($errors));
    }

    public function testAssociativeTagsArrayIsRejected(): void
    {
        $errors = BookmarkValidator::validate([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['name' => 'php'],
        ]);

        $this->assertContains('tags', $this->fields($errors));
    }

    public function testTagsListWithNonStringElementIsRejected(): void
    {
        $errors = BookmarkValidator::validate([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['php', 42],
        ]);

        $this->assertContains('tags', $this->fields($errors));
    }

    public function testPartialAllowsMissingFields(): void
    {
        $this->assertSame([], BookmarkValidator::validate([], true));
        $this->assertSame([], BookmarkValidator::validate(['title' => 'Renamed'], true));
    }

    public function testPartialRejectsPresentButInvalidFields(): void
    {
        $this->assertContains('url', $this->fields(BookmarkValidator::validate(['url' => 'nope'], true)));
        $this->assertContains('title', $this->fields(BookmarkValidator::validate(['title' => '  '], true)));
        $this->assertContains('tags', $this->fields(BookmarkValidator::validate(['tags' => 'php'], true)));
    }
}
