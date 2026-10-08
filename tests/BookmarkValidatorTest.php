<?php

declare(strict_types=1);

namespace App\Tests;

use App\Validation\BookmarkValidator;
use PHPUnit\Framework\TestCase;

final class BookmarkValidatorTest extends TestCase
{
    public function testValidInputReturnsNoErrors(): void
    {
        $errors = BookmarkValidator::validate([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['php', 'sql'],
        ]);

        $this->assertSame([], $errors);
    }

    public function testEmptyTitleIsRejected(): void
    {
        $errors = BookmarkValidator::validate([
            'url' => 'https://example.com',
            'title' => '   ',
        ]);

        $this->assertNotEmpty($errors);
        $this->assertSame('title', $errors[0]['field']);
    }

    public function testMissingTitleIsRejected(): void
    {
        $errors = BookmarkValidator::validate(['url' => 'https://example.com']);

        $this->assertNotEmpty($errors);
        $this->assertSame('title', $errors[0]['field']);
    }

    public function testMissingUrlIsRejected(): void
    {
        $errors = BookmarkValidator::validate(['title' => 'Example']);

        $this->assertNotEmpty($errors);
        $this->assertSame('url', $errors[0]['field']);
    }

    public function testNonHttpUrlIsRejected(): void
    {
        foreach (['ftp://example.com', 'not-a-url', 'example.com', ''] as $url) {
            $errors = BookmarkValidator::validate(['url' => $url, 'title' => 'Example']);

            $this->assertNotEmpty($errors, 'Expected "' . $url . '" to be rejected');
            $this->assertSame('url', $errors[0]['field']);
        }
    }

    public function testHttpAndHttpsUrlsAreAccepted(): void
    {
        $this->assertSame([], BookmarkValidator::validate([
            'url' => 'http://example.com',
            'title' => 'Example',
        ]));
        $this->assertSame([], BookmarkValidator::validate([
            'url' => 'https://example.com/path?q=1',
            'title' => 'Example',
        ]));
    }

    public function testTagsAsNonListIsRejected(): void
    {
        $errors = BookmarkValidator::validate([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => 'php',
        ]);

        $this->assertNotEmpty($errors);
        $this->assertSame('tags', $errors[0]['field']);
    }

    public function testTagsWithNonStringEntriesAreRejected(): void
    {
        $errors = BookmarkValidator::validate([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['php', 42],
        ]);

        $this->assertNotEmpty($errors);
        $this->assertSame('tags', $errors[0]['field']);
    }

    public function testAssociativeTagArrayIsRejected(): void
    {
        $errors = BookmarkValidator::validate([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['first' => 'php'],
        ]);

        $this->assertNotEmpty($errors);
        $this->assertSame('tags', $errors[0]['field']);
    }

    public function testPartialAllowsMissingFields(): void
    {
        $this->assertSame([], BookmarkValidator::validate(['title' => 'New title'], true));
        $this->assertSame([], BookmarkValidator::validate([], true));
    }

    public function testPartialStillRejectsPresentInvalidFields(): void
    {
        $errors = BookmarkValidator::validate(['url' => 'not-a-url'], true);

        $this->assertNotEmpty($errors);
        $this->assertSame('url', $errors[0]['field']);

        $titleErrors = BookmarkValidator::validate(['title' => '  '], true);
        $this->assertNotEmpty($titleErrors);
        $this->assertSame('title', $titleErrors[0]['field']);
    }
}
