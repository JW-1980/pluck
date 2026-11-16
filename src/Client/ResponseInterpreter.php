<?php

declare(strict_types=1);

namespace Pluck\Client;

use Psr\Http\Message\ResponseInterface;

/**
 * Turns the proxy's HTTP answer into a typed {@see Envelope} (PROXY_CONTRACT §4-§5), independent of
 * who sent the request. {@see ProxyClient} uses it; a consumer that must keep its own guarded
 * transport (e.g. NutriCompare's pinned outbound client) sends the {@see RenderRequest} itself and
 * hands the PSR-7 response here, so the status-code rules exist exactly once.
 *
 * @throws InvalidRequest on HTTP 400
 * @throws RateLimited    on HTTP 429
 * @throws PluckException on a wrong secret path (404), 413, 504, any unexpected status and any
 *                        malformed body: it fails closed, it never returns an empty success
 */
final class ResponseInterpreter
{
    public function interpret(ResponseInterface $response): Envelope
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
}
