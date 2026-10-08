<?php

declare(strict_types=1);

namespace App\Tests;

use App\Validation\BookmarkValidator;
use PHPUnit\Framework\TestCase;

final class BookmarkValidatorTest extends TestCase
{
    public function testValidInputHasNoErrors(): void
    {
        $errors = BookmarkValidator::validate([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['php'],
        ]);

        $this->assertSame([], $errors);
    }

    public function testMissingTitleIsRejected(): void
    {
        $errors = BookmarkValidator::validate(['url' => 'https://example.com']);

        $this->assertSame('title', $errors[0]['field']);
    }

    public function testEmptyTitleIsRejected(): void
    {
        $errors = BookmarkValidator::validate([
            'url' => 'https://example.com',
            'title' => '   ',
        ]);

        $this->assertSame('title', $errors[0]['field']);
    }

    public function testMissingUrlIsRejected(): void
    {
        $errors = BookmarkValidator::validate(['title' => 'Example']);

        $this->assertSame('url', $errors[0]['field']);
    }

    public function testNonHttpUrlIsRejected(): void
    {
        $errors = BookmarkValidator::validate([
            'url' => 'ftp://example.com',
            'title' => 'Example',
        ]);

        $this->assertSame('url', $errors[0]['field']);
    }

    public function testGarbageUrlIsRejected(): void
    {
        $errors = BookmarkValidator::validate([
            'url' => 'not-a-url',
            'title' => 'Example',
        ]);

        $this->assertSame('url', $errors[0]['field']);
    }

    public function testTagsMustBeAListOfStrings(): void
    {
        $errors = BookmarkValidator::validate([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['php' => 'yes'],
        ]);

        $this->assertSame('tags', $errors[0]['field']);
    }

    public function testTagsWithNonStringValueAreRejected(): void
    {
        $errors = BookmarkValidator::validate([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['php', 42],
        ]);

        $this->assertSame('tags', $errors[0]['field']);
    }

    public function testPartialToleratesMissingFields(): void
    {
        $this->assertSame([], BookmarkValidator::validate([], true));
        $this->assertSame([], BookmarkValidator::validate(['title' => 'Example'], true));
    }

    public function testPartialStillRejectsInvalidFields(): void
    {
        $errors = BookmarkValidator::validate(['title' => '   '], true);

        $this->assertSame('title', $errors[0]['field']);
    }
}
