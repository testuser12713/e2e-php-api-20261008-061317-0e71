<?php

declare(strict_types=1);

namespace App\Tests;

use App\Validation\BookmarkValidator;
use PHPUnit\Framework\TestCase;

/**
 * Covers the validation rules of POST /api/bookmarks (AC-02).
 */
final class BookmarkValidatorTest extends TestCase
{
    public function testValidInputProducesNoErrors(): void
    {
        $errors = BookmarkValidator::validate([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['php', 'backend'],
        ]);

        $this->assertSame([], $errors);
    }

    public function testTagsAreOptional(): void
    {
        $this->assertSame([], BookmarkValidator::validate([
            'url' => 'https://example.com',
            'title' => 'Example',
        ]));
    }

    public function testMissingTitleIsRejected(): void
    {
        $errors = BookmarkValidator::validate(['url' => 'https://example.com']);

        $this->assertSame('title', $errors[0]['field']);
    }

    public function testEmptyTitleIsRejected(): void
    {
        $errors = BookmarkValidator::validate(['url' => 'https://example.com', 'title' => '   ']);

        $this->assertSame('title', $errors[0]['field']);
    }

    public function testMissingUrlIsRejected(): void
    {
        $errors = BookmarkValidator::validate(['title' => 'Example']);

        $this->assertSame('url', $errors[0]['field']);
    }

    public function testNonHttpUrlIsRejected(): void
    {
        foreach (['not-a-url', 'ftp://example.com', 'example.com'] as $url) {
            $errors = BookmarkValidator::validate(['url' => $url, 'title' => 'Example']);
            $this->assertSame('url', $errors[0]['field'], 'Expected ' . $url . ' to be rejected.');
        }
    }

    public function testUrlThatIsNotAStringIsRejected(): void
    {
        $errors = BookmarkValidator::validate(['url' => 42, 'title' => 'Example']);

        $this->assertSame('url', $errors[0]['field']);
    }

    public function testTagsThatAreNotAListAreRejected(): void
    {
        $errors = BookmarkValidator::validate([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => 'php',
        ]);

        $this->assertSame('tags', $errors[0]['field']);
    }

    public function testTagsThatAreAssociativeAreRejected(): void
    {
        $errors = BookmarkValidator::validate([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['first' => 'php'],
        ]);

        $this->assertSame('tags', $errors[0]['field']);
    }

    public function testTagsThatContainNonStringsAreRejected(): void
    {
        $errors = BookmarkValidator::validate([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['php', 42],
        ]);

        $this->assertSame('tags', $errors[0]['field']);
    }

    public function testFullValidationRequiresBothFields(): void
    {
        $errors = BookmarkValidator::validate([]);

        $fields = array_column($errors, 'field');
        $this->assertContains('url', $fields);
        $this->assertContains('title', $fields);
    }

    public function testPartialValidationToleratesMissingFields(): void
    {
        $this->assertSame([], BookmarkValidator::validate(['title' => 'Example'], true));
        $this->assertSame([], BookmarkValidator::validate(['url' => 'https://example.com'], true));
        $this->assertSame([], BookmarkValidator::validate([], true));
    }

    public function testPartialValidationStillRejectsInvalidFields(): void
    {
        $errors = BookmarkValidator::validate(['url' => 'not-a-url'], true);

        $this->assertSame('url', $errors[0]['field']);
    }
}
