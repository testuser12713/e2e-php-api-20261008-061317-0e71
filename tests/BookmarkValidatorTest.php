<?php

declare(strict_types=1);

namespace App\Tests;

use App\Validation\BookmarkValidator;
use PHPUnit\Framework\TestCase;

/**
 * Covers the validation rules (AC-02): required url and title, http(s) url,
 * tags as a list of strings and the partial (update) behaviour.
 */
final class BookmarkValidatorTest extends TestCase
{
    /**
     * @param list<array{field: string, message: string}> $errors
     */
    private function assertField(array $errors, string $field): void
    {
        $fields = array_column($errors, 'field');
        $this->assertContains($field, $fields);
    }

    public function testValidInputReturnsNoErrors(): void
    {
        $errors = BookmarkValidator::validate([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['php', 'api'],
        ]);

        $this->assertSame([], $errors);
    }

    public function testValidInputWithoutTagsReturnsNoErrors(): void
    {
        $errors = BookmarkValidator::validate([
            'url' => 'https://example.com',
            'title' => 'Example',
        ]);

        $this->assertSame([], $errors);
    }

    public function testMissingUrlIsRejected(): void
    {
        $errors = BookmarkValidator::validate(['title' => 'Example']);

        $this->assertField($errors, 'url');
    }

    public function testMissingTitleIsRejected(): void
    {
        $errors = BookmarkValidator::validate(['url' => 'https://example.com']);

        $this->assertField($errors, 'title');
    }

    public function testEmptyTitleAfterTrimIsRejected(): void
    {
        $errors = BookmarkValidator::validate([
            'url' => 'https://example.com',
            'title' => '   ',
        ]);

        $this->assertField($errors, 'title');
    }

    public function testNonHttpUrlIsRejected(): void
    {
        foreach (['not-a-url', 'ftp://example.com', 'example.com'] as $url) {
            $errors = BookmarkValidator::validate(['url' => $url, 'title' => 'Example']);
            $this->assertField($errors, 'url');
        }
    }

    public function testTagsNotAListIsRejected(): void
    {
        $errors = BookmarkValidator::validate([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['key' => 'value'],
        ]);

        $this->assertField($errors, 'tags');
    }

    public function testTagsWithNonStringValuesAreRejected(): void
    {
        $errors = BookmarkValidator::validate([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['php', 42],
        ]);

        $this->assertField($errors, 'tags');
    }

    public function testPartialToleratesMissingFields(): void
    {
        $this->assertSame([], BookmarkValidator::validate(['title' => 'Example'], true));
        $this->assertSame([], BookmarkValidator::validate([], true));
    }

    public function testPartialStillRejectsInvalidValues(): void
    {
        $this->assertField(
            BookmarkValidator::validate(['url' => 'not-a-url'], true),
            'url'
        );
        $this->assertField(
            BookmarkValidator::validate(['title' => '   '], true),
            'title'
        );
    }
}
