<?php

declare(strict_types=1);

namespace Pluck\Http;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Pluck\Support\BlockedTarget;
use Pluck\Contracts\TargetValidator;
use Pluck\Support\TargetGuard;
use Pluck\Support\UserAgent;

/**
 * One guarded, bounded direct GET — the "boundary exception" fetch of
 * FUNCTIONAL_DESIGN §5 (robots.txt, feeds) and the plain-HTTP rung of the
 * direct driver chain. It does NOT render and does NOT judge blocks; it just
 * moves bytes safely:
 *
 *  - every hop is SSRF-vetted and (with cURL) the connection pinned to the vetted IP;
 *  - redirects are followed by hand: bounded, same site only, never https->http;
 *  - the DECODED body is capped mid-transfer by {@see CappedStream} (a gzip bomb
 *    is abandoned, not buffered); a declared Content-Length over the cap is
 *    refused before reading;
 *  - transport errors are scrubbed and raised as {@see FetchFailed}.
 *
 * Defaults trace to recorded values: 3 redirects and 5 s connect timeout
 * (WelZoLekker ProductPageFetcher), 3 MB cap and 60 s budget (PROXY_CONTRACT §3
 * "recorded donor cap" / §2 `max_timeout_ms` default).
 */
final class PoliteFetcher
{
    public const DEFAULT_MAX_BYTES = 3 * 1024 * 1024;
    public const DEFAULT_TIMEOUT_SECONDS = 60;
    public const DEFAULT_CONNECT_TIMEOUT_SECONDS = 5;
    public const DEFAULT_MAX_REDIRECTS = 3;

    private const READ_CHUNK = 65_536;

    private readonly ClientInterface $http;
    private readonly bool $pinConnection;

    public function __construct(
        ?ClientInterface $http = null,
        private readonly TargetValidator $guard = new TargetGuard(),
        private readonly UserAgent $userAgents = new UserAgent(),
        private readonly int $maxBytes = self::DEFAULT_MAX_BYTES,
        private readonly int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        private readonly int $maxRedirects = self::DEFAULT_MAX_REDIRECTS,
        private readonly string $acceptLanguage = 'nl-NL,nl;q=0.9',
        ?bool $pinConnection = null,
    ) {
        // CURLOPT_RESOLVE only means something to Guzzle's cURL handler; Guzzle 8 even
        // rejects it on the stream handler. Default: pin whenever cURL is present.
        $this->pinConnection = $pinConnection ?? (extension_loaded('curl') && defined('CURLOPT_RESOLVE'));
        $this->http = $http ?? new Client();
    }

    /** @throws FetchFailed */
    public function fetch(string $url): Fetched
    {
        $current = $url;
        $startHost = (string) parse_url($url, PHP_URL_HOST);

        for ($hop = 0; $hop <= $this->maxRedirects; $hop++) {
            try {
                $target = $this->guard->vet($current);
            } catch (BlockedTarget $e) {
                throw new FetchFailed('Target refused: '.$e->getMessage(), 'blocked_target', $e);
            }

            $response = $this->request($current, $target);
            $status = $response->getStatusCode();

            if ($status >= 300 && $status < 400 && $response->hasHeader('Location')) {
                if ($hop === $this->maxRedirects) {
                    throw new FetchFailed("More than {$this->maxRedirects} redirects.", 'too_many_redirects');
                }

                $next = (string) UriResolver::resolve(new Uri($current), new Uri($response->getHeaderLine('Location')));
                $this->assertRedirectAllowed($current, $next, $startHost);
                $current = $next;

                continue;
            }

            $headers = [];
            foreach ($response->getHeaders() as $name => $values) {
                $headers[strtolower($name)] = implode(', ', $values);
            }

            return new Fetched($status, $current, BoundedBody::read($response, $this->maxBytes), $headers, $hop);
        }

        throw new FetchFailed("More than {$this->maxRedirects} redirects.", 'too_many_redirects');
    }

    /** @param array{scheme:string,host:string,port:int,ip:string} $target */
    private function request(string $url, array $target): \Psr\Http\Message\ResponseInterface
    {
        $sink = new CappedStream($this->maxBytes);
        $options = [
            'allow_redirects' => false,
            'http_errors' => false,
            // A capped sink, not `stream => true`: Guzzle routes streamed requests through PHP's
            // stream handler, which would bypass the cURL connection pin and buffer first.
            'sink' => $sink,
            'connect_timeout' => self::DEFAULT_CONNECT_TIMEOUT_SECONDS,
            'timeout' => $this->timeoutSeconds,
            'headers' => [
                'User-Agent' => $this->userAgents->forHost($target['host']),
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => $this->acceptLanguage,
            ],
        ];

        // Pin the connection to the vetted address so DNS cannot change between check and connect.
        if ($this->pinConnection) {
            $ip = str_contains($target['ip'], ':') ? '['.$target['ip'].']' : $target['ip'];
            $options['curl'] = [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$ip}"]];
        }

        try {
            $response = $this->http->request('GET', $url, $options);
        } catch (FetchFailed $e) {
            throw $e;
        } catch (GuzzleException $e) {
            // The capped sink aborts the transfer with a short write; the client reports that as a write error.
            if ($sink->exceeded()) {
                throw $sink->tooLarge();
            }

            throw new FetchFailed('Transport error: '.$this->scrub($e->getMessage(), $url), 'transport', $e);
        }

        if ($sink->exceeded()) {
            throw $sink->tooLarge();
        }

        return $response;
    }

    private function assertRedirectAllowed(string $from, string $to, string $startHost): void
    {
        $fromParts = parse_url($from) ?: [];
        $toParts = parse_url($to) ?: [];

        if (($fromParts['scheme'] ?? '') === 'https' && ($toParts['scheme'] ?? '') !== 'https') {
            throw new FetchFailed('Redirect downgrades https to http.', 'redirect_downgrade');
        }

        if (! self::sameSite($startHost, (string) ($toParts['host'] ?? ''))) {
            throw new FetchFailed('Redirect leaves the shop host (to '.strtolower((string) ($toParts['host'] ?? '?')).').', 'cross_host_redirect');
        }
    }

    /** Same host, or the www.-toggled variant of it (the donors' "same domain" rule). */
    private static function sameSite(string $a, string $b): bool
    {
        $strip = static fn (string $h): string => preg_replace('/^www\./', '', strtolower($h)) ?? strtolower($h);

        return $strip($a) !== '' && $strip($a) === $strip($b);
    }

    private function scrub(string $message, string $url): string
    {
        // Guzzle embeds the full URL (possibly with tokens in the query); keep only the host.
        $host = (string) parse_url($url, PHP_URL_HOST);

        return $url !== '' ? str_replace($url, $host !== '' ? "[{$host}]" : '[url]', $message) : $message;
    }
}
