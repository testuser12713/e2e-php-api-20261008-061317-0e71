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

    public function testTagsAreOptional(): void
    {
        $errors = BookmarkValidator::validate([
            'url' => 'https://example.com',
            'title' => 'Example',
        ]);

        $this->assertSame([], $errors);
    }

    public function testMissingUrlIsReported(): void
    {
        $errors = BookmarkValidator::validate(['title' => 'Example']);

        $this->assertSame('url', $errors[0]['field']);
    }

    public function testMissingTitleIsReported(): void
    {
        $errors = BookmarkValidator::validate(['url' => 'https://example.com']);

        $this->assertSame('title', $errors[0]['field']);
    }

    public function testWhitespaceOnlyTitleIsReported(): void
    {
        $errors = BookmarkValidator::validate([
            'url' => 'https://example.com',
            'title' => '   ',
        ]);

        $this->assertSame('title', $errors[0]['field']);
    }

    public function testNonHttpUrlIsReported(): void
    {
        foreach (['example.com', 'ftp://example.com', 'not a url'] as $url) {
            $errors = BookmarkValidator::validate([
                'url' => $url,
                'title' => 'Example',
            ]);

            $this->assertNotSame([], $errors, sprintf('Expected "%s" to be rejected', $url));
            $this->assertSame('url', $errors[0]['field']);
        }
    }

    public function testTagsMustBeAList(): void
    {
        $errors = BookmarkValidator::validate([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => 'php',
        ]);

        $this->assertNotSame([], $errors);
        $this->assertSame('tags', $errors[0]['field']);
    }

    public function testTagsMustContainOnlyStrings(): void
    {
        $errors = BookmarkValidator::validate([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['php', 42],
        ]);

        $this->assertNotSame([], $errors);
        $this->assertSame('tags', $errors[0]['field']);
    }

    public function testPartialToleratesMissingFields(): void
    {
        $this->assertSame([], BookmarkValidator::validate([], true));
        $this->assertSame([], BookmarkValidator::validate(['title' => 'Example'], true));
    }

    public function testPartialStillRejectsInvalidFields(): void
    {
        $errors = BookmarkValidator::validate(['url' => 'ftp://example.com'], true);

        $this->assertNotSame([], $errors);
        $this->assertSame('url', $errors[0]['field']);
    }
}
