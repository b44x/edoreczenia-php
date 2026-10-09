<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class Http
{
    public static function factory(): Psr17Factory
    {
        return new Psr17Factory();
    }

    /**
     * @param array<mixed>|string $body
     * @param array<string, string> $headers
     */
    public static function json(int $status, array|string $body, array $headers = []): ResponseInterface
    {
        $content = \is_string($body) ? $body : json_encode($body, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);

        return new Response($status, ['Content-Type' => 'application/json'] + $headers, $content);
    }

    public static function fixture(int $status, string $name): ResponseInterface
    {
        return self::json($status, self::fixtureBody($name));
    }

    public static function fixtureBody(string $name): string
    {
        $content = file_get_contents(__DIR__ . '/../Fixtures/' . $name);
        if ($content === false) {
            throw new \RuntimeException('Missing fixture ' . $name);
        }

        return $content;
    }

    /**
     * @return array<mixed>
     */
    public static function jsonBody(RequestInterface $request): array
    {
        $decoded = json_decode((string) $request->getBody(), true, 64, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new \RuntimeException('Request body is not a JSON object.');
        }

        return $decoded;
    }

    public static function tokenResponse(string $token = 'test-access-token', int $expiresIn = 300): ResponseInterface
    {
        return self::json(200, [
            'access_token' => $token,
            'expires_in' => $expiresIn,
            'refresh_expires_in' => 0,
            'token_type' => 'Bearer',
            'not-before-policy' => 1612451286,
            'scope' => 'system-attributes',
        ]);
    }
}
