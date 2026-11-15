<?php

declare(strict_types=1);

namespace Pluck\Http;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Pluck\Client\InvalidRequest;
use Pluck\Client\Outcome;
use Pluck\Contracts\SourceDriver;
use Pluck\Support\BlockDetector;
use Pluck\Support\BlockedTarget;
use Pluck\Contracts\TargetValidator;
use Pluck\Support\TargetGuard;
use Pluck\Throttle\CircuitBreaker;

/**
 * Direct FlareSolverr rung (`POST {endpoint}/v1`, `cmd=request.get`) — the one
 * implementation that replaces WelZoLekker's `ProductPageFetcher`, NutriCompare's
 * `FlareSolverrSourceDriver` and Inktomi's `fetcher.py` client (INVENTORY §5).
 *
 * Semantics carried over from the donors:
 *  - FlareSolverr wraps the page in its own reply, so a 200 from FlareSolverr can
 *    still hold a 403 from the shop: BOTH statuses are judged (WelZoLekker).
 *  - `maxTimeout` is whole milliseconds; the HTTP client gets 10 s of headroom
 *    (WelZoLekker, Inktomi `ToMilliseconds`).
 *  - A failing FlareSolverr opens a 120 s breaker; while open the driver reports
 *    `upstream_error` instead of silently skipping (Inktomi).
 *  - The target URL is SSRF-vetted *before* it is handed to FlareSolverr, which
 *    sits on the internal network and has no authentication of its own.
 *
 * The endpoint is operator configuration, not a target, and is never vetted.
 */
final class FlareSolverrSourceDriver implements SourceDriver
{
    public const HTTP_HEADROOM_SECONDS = 10;

    private readonly ClientInterface $http;
    private readonly string $endpoint;

    public function __construct(
        string $endpoint,
        ?ClientInterface $http = null,
        private readonly TargetValidator $guard = new TargetGuard(),
        private readonly BlockDetector $detector = new BlockDetector(),
        private readonly ?CircuitBreaker $breaker = null,
        private readonly int $maxTimeoutSeconds = PoliteFetcher::DEFAULT_TIMEOUT_SECONDS,
        private readonly int $maxBytes = PoliteFetcher::DEFAULT_MAX_BYTES,
    ) {
        $this->endpoint = rtrim($endpoint, '/');
        $this->http = $http ?? new Client();
    }

    public function name(): string
    {
        return 'flaresolverr';
    }

    public function fetch(string $url): SourceResult
    {
        try {
            $this->guard->vet($url);
        } catch (BlockedTarget $e) {
            throw new InvalidRequest('Target refused: '.$e->getMessage(), 'private_target', $e);
        }

        if (($remaining = $this->breaker?->remaining()) !== null) {
            return new SourceResult(Outcome::UpstreamError, $this->name(), retryAfterSeconds: $remaining, error: "FlareSolverr circuit breaker open for another {$remaining}s.");
        }

        try {
            $reply = $this->post($url);
        } catch (FetchFailed $e) {
            $this->breaker?->recordFailure();

            return new SourceResult(Outcome::UpstreamError, $this->name(), error: $e->reason.': '.$e->getMessage());
        }

        $status = $reply['status'] ?? null;
        $solution = $reply['solution'] ?? null;

        if ($status !== 'ok' || ! is_array($solution)) {
            $message = is_string($reply['message'] ?? null) ? $reply['message'] : 'no message';

            // FlareSolverr reached the shop and lost the fight: that is a block, not an outage.
            if (stripos($message, 'challenge') !== false) {
                return new SourceResult(Outcome::Blocked, $this->name(), blockSignal: 'flaresolverr: '.$message);
            }

            $this->breaker?->recordFailure();

            return new SourceResult(Outcome::UpstreamError, $this->name(), error: 'FlareSolverr failed: '.$message);
        }

        $this->breaker?->recordSuccess();

        $shopStatus = $solution['status'] ?? null;
        $html = $solution['response'] ?? null;
        $finalUrl = is_string($solution['url'] ?? null) ? $solution['url'] : $url;

        if (! is_int($shopStatus) || ! is_string($html)) {
            return new SourceResult(Outcome::UpstreamError, $this->name(), error: 'FlareSolverr returned no usable solution.');
        }
        if (strlen($html) > $this->maxBytes) {
            return new SourceResult(Outcome::ShopError, $this->name(), $shopStatus, $finalUrl, error: "content_too_large: the page is larger than {$this->maxBytes} bytes.");
        }

        return HttpSourceDriver::judge($this->name(), $shopStatus, $finalUrl, $html, $this->detector);
    }

    /**
     * @return array<string,mixed>
     * @throws FetchFailed
     */
    private function post(string $url): array
    {
        try {
            $response = $this->http->request('POST', $this->endpoint.'/v1', [
                'json' => ['cmd' => 'request.get', 'url' => $url, 'maxTimeout' => $this->maxTimeoutSeconds * 1000],
                'timeout' => $this->maxTimeoutSeconds + self::HTTP_HEADROOM_SECONDS,
                'connect_timeout' => PoliteFetcher::DEFAULT_CONNECT_TIMEOUT_SECONDS,
                'http_errors' => false,
                'stream' => true,
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (GuzzleException $e) {
            throw new FetchFailed('FlareSolverr unreachable: '.preg_replace('#https?://\S+#', '[endpoint]', $e->getMessage()), 'unreachable', $e);
        }

        if ($response->getStatusCode() !== 200) {
            // The body of an error page is not trusted or surfaced.
            throw new FetchFailed("FlareSolverr answered HTTP {$response->getStatusCode()}.", 'http_status');
        }

        // The reply wraps the page, so allow JSON-escaping overhead on top of the page cap.
        $json = BoundedBody::read($response, $this->maxBytes * 2 + 65_536);
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            throw new FetchFailed('FlareSolverr answered with invalid JSON.', 'malformed');
        }

        return $decoded;
    }
}
