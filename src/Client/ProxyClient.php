<?php

declare(strict_types=1);

namespace Pluck\Client;

use GuzzleHttp\ClientInterface as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;

/**
 * Sends a URL to `pluck-proxy` and returns the typed answer. PHP never renders
 * and never strips (owner directive #13/#15); this class only speaks the wire
 * contract of docs/PROXY_CONTRACT.md.
 *
 * `$baseUrl` is the configured front-door URL, e.g.
 * `https://scraper.example.test/<SECRET>/v1`. The secret path is a credential:
 * it is scrubbed from every exception message this class raises.
 */
final class ProxyClient
{
    /**
     * Headroom added on top of the request's own render budget so the proxy can
     * answer before the HTTP client gives up. Recorded donor value:
     * WelZoLekker's ProductPageFetcher leaves FlareSolverr 10 s of room.
     */
    public const HTTP_HEADROOM_SECONDS = 10;

    private readonly string $baseUrl;
    private readonly RequestFactoryInterface $requests;
    private readonly StreamFactoryInterface $streams;

    /** @param array<string,string> $headers extra headers (e.g. a gateway credential); never logged */
    public function __construct(
        string $baseUrl,
        private readonly ClientInterface $http,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        private readonly array $headers = [],
    ) {
        $baseUrl = rtrim($baseUrl, '/');
        $parts = parse_url($baseUrl);
        if (! is_array($parts) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || ! isset($parts['host'])) {
            throw new InvalidRequest('The proxy base URL must be an absolute http(s) URL.');
        }

        $this->baseUrl = $baseUrl;
        $factory = new HttpFactory();
        $this->requests = $requestFactory ?? $factory;
        $this->streams = $streamFactory ?? $factory;
    }

    /**
     * @throws InvalidRequest when the proxy rejected the request (HTTP 400)
     * @throws RateLimited    on HTTP 429
     * @throws PluckException on transport failure, front-door 404, timeouts and any malformed answer
     */
    public function render(RenderRequest $request): Envelope
    {
        try {
            $payload = json_encode($request->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $e) {
            throw new InvalidRequest('The request could not be encoded as JSON: '.$e->getMessage(), 'invalid_options', $e);
        }

        $httpRequest = $this->requests->createRequest('POST', $this->baseUrl.'/render')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withBody($this->streams->createStream($payload));
        foreach ($this->headers as $name => $value) {
            $httpRequest = $httpRequest->withHeader($name, $value);
        }

        try {
            $response = $this->http instanceof GuzzleClient
                ? $this->http->send($httpRequest, [
                    'timeout' => intdiv($request->maxTimeoutMs, 1000) + self::HTTP_HEADROOM_SECONDS,
                    'http_errors' => false,
                ])
                : $this->http->sendRequest($httpRequest);
        } catch (ClientExceptionInterface|Throwable $e) {
            throw new PluckException('The Pluck proxy could not be reached: '.$this->scrub($e->getMessage()), 0, $e);
        }

        return (new ResponseInterpreter())->interpret($response);
    }

    private function scrub(string $message): string
    {
        $path = (string) (parse_url($this->baseUrl, PHP_URL_PATH) ?? '');
        $message = str_replace($this->baseUrl, '[proxy]', $message);

        return $path !== '' && $path !== '/' ? str_replace($path, '/[redacted]', $message) : $message;
    }
}
