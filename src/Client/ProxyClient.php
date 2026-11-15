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

        return $this->interpret($response);
    }

    private function interpret(ResponseInterface $response): Envelope
    {
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        return match (true) {
            $status === 200 => $this->envelope($this->decode($body), $status),
            $status === 400 => throw new InvalidRequest(
                'The proxy rejected the request: '.$this->errorCode($this->decodeLeniently($body)),
                $this->errorCode($this->decodeLeniently($body)),
            ),
            $status === 404 => throw new PluckException('The proxy front door answered 404: the secret path in the configured URL is wrong or was rotated.', 404),
            $status === 413 => throw new PluckException('The request body is over the proxy cap (HTTP 413).', 413),
            $status === 429 => throw new RateLimited($this->retryAfter($response, $this->decodeLeniently($body))),
            $status === 502 => $this->envelope($this->decode($body), $status),
            $status === 504 => throw new PluckException('The proxy gave up: max_timeout_ms exceeded (HTTP 504).', 504),
            default => throw new PluckException("Unexpected HTTP {$status} from the Pluck proxy.", $status),
        };
    }

    /** @param array<string,mixed> $data */
    private function envelope(array $data, int $status): Envelope
    {
        $envelope = Envelope::fromArray($data);

        // 502 is the wire form of upstream_error; any other outcome under 502 is a contract violation.
        if ($status === 502 && $envelope->outcome !== Outcome::UpstreamError) {
            throw new PluckException("HTTP 502 carried outcome '{$envelope->outcome->value}', expected 'upstream_error'.");
        }

        return $envelope;
    }

    /** @return array<string,mixed> */
    private function decode(string $body): array
    {
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new PluckException('The proxy answer is not valid JSON: '.$e->getMessage(), 0, $e);
        }

        if (! is_array($data) || array_is_list($data) && $data !== []) {
            throw new PluckException('The proxy answer is not a JSON object.');
        }

        return $data;
    }

    /**
     * Error statuses may come from the front door (Caddy) with a non-JSON body;
     * their meaning is in the status code, so a bad body must not hide it.
     *
     * @return array<string,mixed>
     */
    private function decodeLeniently(string $body): array
    {
        $data = json_decode($body, true);

        return is_array($data) && ! array_is_list($data) ? $data : [];
    }

    /** @param array<string,mixed> $data */
    private function errorCode(array $data): string
    {
        $error = $data['error'] ?? null;

        return is_string($error) ? $error : 'unspecified';
    }

    /** @param array<string,mixed> $data */
    private function retryAfter(ResponseInterface $response, array $data): ?int
    {
        $fromBody = $data['retry_after_seconds'] ?? null;
        if (is_int($fromBody) && $fromBody >= 0) {
            return $fromBody;
        }

        $header = trim($response->getHeaderLine('Retry-After'));

        return ctype_digit($header) ? (int) $header : null;
    }

    private function scrub(string $message): string
    {
        $path = (string) (parse_url($this->baseUrl, PHP_URL_PATH) ?? '');
        $message = str_replace($this->baseUrl, '[proxy]', $message);

        return $path !== '' && $path !== '/' ? str_replace($path, '/[redacted]', $message) : $message;
    }
}
