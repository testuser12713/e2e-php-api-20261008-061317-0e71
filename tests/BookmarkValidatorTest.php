<?php

declare(strict_types=1);

namespace App\Tests;

use App\Validation\BookmarkValidator;
use PHPUnit\Framework\TestCase;

final class BookmarkValidatorTest extends TestCase
{
    /**
     * @param list<array{field: string, message: string}> $errors
     */
    private function assertHasFieldError(array $errors, string $field): void
    {
        foreach ($errors as $error) {
            if ($error['field'] === $field) {
                $this->assertNotSame('', $error['message']);

                return;
            }
        }

        $this->fail(sprintf('Expected a validation error for field "%s"', $field));
    }

    public function testValidInputHasNoErrors(): void
    {
        $errors = BookmarkValidator::validate([
            'url' => 'https://example.com',
            'title' => 'Example',
            'tags' => ['php', 'api'],
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
        $this->assertHasFieldError(BookmarkValidator::validate(['title' => 'Example']), 'url');
    }

    public function testEmptyOrWhitespaceUrlIsReported(): void
    {
        $this->assertHasFieldError(BookmarkValidator::validate(['url' => '   ', 'title' => 'Example']), 'url');
    }

    public function testNonHttpUrlIsReported(): void
    {
        $this->assertHasFieldError(BookmarkValidator::validate(['url' => 'ftp://example.com', 'title' => 'Example']), 'url');
        $this->assertHasFieldError(BookmarkValidator::validate(['url' => 'example.com', 'title' => 'Example']), 'url');
    }

    public function testMissingTitleIsReported(): void
    {
        $this->assertHasFieldError(BookmarkValidator::validate(['url' => 'https://example.com']), 'title');
    }

    public function testWhitespaceOnlyTitleIsReported(): void
    {
        $this->assertHasFieldError(
            BookmarkValidator::validate(['url' => 'https://example.com', 'title' => '   ']),
            'title'
        );
    }

    public function testTagsMustBeAListOfStrings(): void
    {
        $this->assertHasFieldError(
            BookmarkValidator::validate(['url' => 'https://example.com', 'title' => 'Example', 'tags' => 'php']),
            'tags'
        );
        $this->assertHasFieldError(
            BookmarkValidator::validate(['url' => 'https://example.com', 'title' => 'Example', 'tags' => ['php', 42]]),
            'tags'
        );
    }

    public function testPartialSkipsTheMissingFieldCheck(): void
    {
        $this->assertSame([], BookmarkValidator::validate(['title' => 'New title'], true));
        $this->assertSame([], BookmarkValidator::validate(['url' => 'https://example.com'], true));
        $this->assertSame([], BookmarkValidator::validate([], true));
    }

    public function testPartialStillValidatesPresentFields(): void
    {
        $this->assertHasFieldError(BookmarkValidator::validate(['url' => 'not a url'], true), 'url');
        $this->assertHasFieldError(BookmarkValidator::validate(['title' => '  '], true), 'title');
    }
}
