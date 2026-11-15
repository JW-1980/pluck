<?php

declare(strict_types=1);

namespace Pluck\Tests\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Pluck\Support\TargetGuard;

/** Test helpers: a Guzzle client over a queue of canned responses, recording every request. */
final class Http
{
    /**
     * @param list<Response|\Throwable> $queue
     * @param-out list<array{request:\Psr\Http\Message\RequestInterface,options:array<string,mixed>}> $history
     */
    public static function client(array $queue, ?array &$history = null): Client
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($history));

        return new Client(['handler' => $stack]);
    }

    /** A guard whose DNS answers with a fixed public address. */
    public static function publicGuard(string ...$ips): TargetGuard
    {
        return new TargetGuard(static fn (): array => $ips === [] ? ['93.184.216.34'] : array_values($ips));
    }

    /** @param array<string,mixed> $extra */
    public static function envelope(array $extra = []): array
    {
        return $extra + [
            'contract_version' => 1,
            'request_id' => 'req-1',
            'outcome' => 'fetched',
            'shop_status' => 200,
            'final_url' => 'https://shop.example/p/1',
            'title' => 'Product',
            'html' => '<div>ok</div>',
            'json_ld' => [],
            'meta' => ['renderer' => 'flaresolverr', 'cache' => 'miss'],
        ];
    }

    public static function json(array $body, int $status = 200, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, json_encode($body, JSON_THROW_ON_ERROR));
    }
}
