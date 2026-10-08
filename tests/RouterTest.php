<?php

declare(strict_types=1);

namespace App\Tests;

use App\Http\Request;
use App\Http\Response;
use App\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function request(string $method, string $path): Request
    {
        return new Request($method, $path);
    }

    public function testMatchesAnExactRoute(): void
    {
        $router = new Router();
        $router->add('GET', '/api/health', static function (Request $request, array $params): Response {
            return Response::json(['status' => 'ok']);
        });

        $response = $router->dispatch($this->request('GET', '/api/health'));

        $this->assertSame(200, $response->status);
        $this->assertSame('{"status":"ok"}', $response->body);
    }

    public function testMatchesPlaceholderAndPassesParams(): void
    {
        $router = new Router();
        $seen = null;
        $router->add('GET', '/api/bookmarks/{id}', static function (Request $request, array $params) use (&$seen): Response {
            $seen = $params;

            return Response::json(['id' => $params['id']]);
        });

        $response = $router->dispatch($this->request('GET', '/api/bookmarks/42'));

        $this->assertSame(200, $response->status);
        $this->assertSame(['id' => '42'], $seen);
    }

    public function testPlaceholderDoesNotSpanMultipleSegments(): void
    {
        $router = new Router();
        $router->add('GET', '/api/bookmarks/{id}', static function (Request $request, array $params): Response {
            return Response::json([]);
        });

        $this->assertSame(404, $router->dispatch($this->request('GET', '/api/bookmarks/1/2'))->status);
    }

    public function testUnknownPathAnswers404AsJson(): void
    {
        $router = new Router();
        $router->add('GET', '/api/health', static function (Request $request, array $params): Response {
            return Response::json(['status' => 'ok']);
        });

        $response = $router->dispatch($this->request('GET', '/api/does-not-exist'));

        $this->assertSame(404, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
        $this->assertSame('{"error":{"code":"not_found","message":"Not Found","details":{}}}', $response->body);
    }

    public function testKnownPathWithUnsupportedMethodAnswers405WithAllow(): void
    {
        $router = new Router();
        $router->add('GET', '/api/bookmarks', static function (Request $request, array $params): Response {
            return Response::json([]);
        });
        $router->add('POST', '/api/bookmarks', static function (Request $request, array $params): Response {
            return Response::json([], 201);
        });

        $response = $router->dispatch($this->request('DELETE', '/api/bookmarks'));

        $this->assertSame(405, $response->status);
        $this->assertArrayHasKey('Allow', $response->headers);
        $this->assertSame('GET, POST', $response->headers['Allow']);
        $this->assertStringContainsString('"code":"method_not_allowed"', $response->body);
    }

    public function testTrailingSlashIsADifferentPath(): void
    {
        $router = new Router();
        $router->add('GET', '/api/bookmarks', static function (Request $request, array $params): Response {
            return Response::json([]);
        });

        $this->assertSame(404, $router->dispatch($this->request('GET', '/api/bookmarks/'))->status);
    }
}
