<?php
declare(strict_types=1);

namespace Kios\Application;

use Closure;
use Kios\Infrastructure\PublicTokenDisplayService;
use PDO;
use Throwable;

final class PublicTokenDisplayController
{
    /** @var Closure():PDO */
    private readonly Closure $connect;

    public function __construct(callable $connect)
    {
        $this->connect = Closure::fromCallable($connect);
    }

    /** @return array{status:int,headers:array<string,string>,body:string} */
    public function respond(string $method): array
    {
        $headers = [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];
        if (strtoupper($method) !== 'GET') {
            $headers['Allow'] = 'GET';
            return ['status' => 405, 'headers' => $headers, 'body' => '{"error":"Method not allowed."}'];
        }

        try {
            $pdo = ($this->connect)();
            $snapshot = (new PublicTokenDisplayService($pdo))->snapshot();
            return [
                'status' => 200,
                'headers' => $headers,
                'body' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
            ];
        } catch (Throwable $error) {
            \Kios\Infrastructure\RequestObservability::recordCaughtException($error, 'public_display_refresh');
            return [
                'status' => 503,
                'headers' => $headers,
                'body' => '{"error":"The display is temporarily unavailable."}',
            ];
        }
    }
}
