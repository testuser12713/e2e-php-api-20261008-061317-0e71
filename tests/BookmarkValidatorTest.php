<?php

declare(strict_types=1);

namespace App\Tests;

use App\Validation\BookmarkValidator;
use PHPUnit\Framework\TestCase;

final class BookmarkValidatorTest extends TestCase
{
    public function testAcceptsAValidBookmark(): void
    {
        $errors = BookmarkValidator::validate([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['php', 'api'],
        ]);

        $this->assertSame([], $errors);
    }

    public function testAcceptsAnHttpUrlWithoutTags(): void
    {
        $errors = BookmarkValidator::validate([
            'url' => 'http://example.com/path?q=1',
            'title' => 'Example',
        ]);

        $this->assertSame([], $errors);
    }

    public function testMissingUrlIsAnError(): void
    {
        $errors = BookmarkValidator::validate(['title' => 'Example']);

        $this->assertCount(1, $errors);
        $this->assertSame('url', $errors[0]['field']);
    }

    public function testInvalidUrlIsAnError(): void
    {
        foreach (['not-a-url', 'ftp://example.com', 'javascript:alert(1)'] as $url) {
            $errors = BookmarkValidator::validate(['url' => $url, 'title' => 'Example']);
            $this->assertNotEmpty($errors, 'Expected ' . $url . ' to be rejected');
            $this->assertSame('url', $errors[0]['field']);
        }
    }

    public function testMissingOrEmptyTitleIsAnError(): void
    {
        $missing = BookmarkValidator::validate(['url' => 'https://example.com']);
        $this->assertSame('title', $missing[0]['field']);

        $empty = BookmarkValidator::validate(['url' => 'https://example.com', 'title' => '   ']);
        $this->assertSame('title', $empty[0]['field']);
    }

    public function testTagsMustBeAListOfStrings(): void
    {
        $notAList = BookmarkValidator::validate([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => 'php',
        ]);
        $this->assertSame('tags', $notAList[0]['field']);

        $notStrings = BookmarkValidator::validate([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['php', 42],
        ]);
        $this->assertSame('tags', $notStrings[0]['field']);
    }

    public function testPartialSkipsMissingFields(): void
    {
        $this->assertSame([], BookmarkValidator::validate(['title' => 'New'], true));
        $this->assertSame([], BookmarkValidator::validate([], true));
    }

    public function testPartialStillValidatesSuppliedFields(): void
    {
        $errors = BookmarkValidator::validate(['url' => 'nope'], true);

        $this->assertSame('url', $errors[0]['field']);
    }
}
