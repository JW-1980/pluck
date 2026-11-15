<?php

declare(strict_types=1);

namespace Pluck\Client;

/**
 * A validated `POST /v1/render` body (PROXY_CONTRACT §2). Validation happens
 * client-side too, so a consumer bug fails fast and loudly instead of costing a
 * round trip; the proxy validates again (it is the authority).
 *
 * Limits are the contract's, not invented here.
 */
final readonly class RenderRequest
{
    public const MAX_URL_LENGTH = 2048;
    public const MAX_XPATHS = 16;
    public const MAX_XPATH_LENGTH = 500;
    public const MAX_TIMEOUT_MS = 90_000;
    public const MAX_WAIT_MS = 5_000;
    public const MAX_TTL_SECONDS = 86_400;

    /**
     * @param array<string,string> $keepXpaths name => expression; required iff $strip is XPath
     */
    public function __construct(
        public string $url,
        public StripMode $strip = StripMode::Conservative,
        public array $keepXpaths = [],
        public bool $preserveScripts = false,
        public int $maxTimeoutMs = 60_000,
        public int $waitMs = 0,
        public int $ttlSeconds = 0,
        public bool $followRedirects = true,
    ) {
        $this->validate();
    }

    /** @param array<string,string> $keepXpaths */
    public static function xpath(string $url, array $keepXpaths, int $ttlSeconds = 0): self
    {
        return new self($url, StripMode::XPath, $keepXpaths, ttlSeconds: $ttlSeconds);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $body = [
            'url' => $this->url,
            'strip' => $this->strip->value,
            'preserve_scripts' => $this->preserveScripts,
            'max_timeout_ms' => $this->maxTimeoutMs,
            'wait_ms' => $this->waitMs,
            'ttl_seconds' => $this->ttlSeconds,
            'follow_redirects' => $this->followRedirects,
        ];

        if ($this->strip === StripMode::XPath) {
            $body['keep_xpaths'] = $this->keepXpaths;
        }

        return $body;
    }

    private function validate(): void
    {
        $url = $this->url;
        if ($url === '' || strlen($url) > self::MAX_URL_LENGTH) {
            throw new InvalidRequest('The URL must be 1-'.self::MAX_URL_LENGTH.' characters.', 'invalid_url');
        }

        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        if (! in_array($scheme, ['http', 'https'], true) || ! isset($parts['host']) || $parts['host'] === '') {
            throw new InvalidRequest('Only absolute http(s) URLs can be rendered.', 'invalid_url');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidRequest('Credentials in the URL are not allowed.', 'invalid_url');
        }

        if ($this->maxTimeoutMs < 1 || $this->maxTimeoutMs > self::MAX_TIMEOUT_MS) {
            throw new InvalidRequest('max_timeout_ms must be 1-'.self::MAX_TIMEOUT_MS.'.', 'invalid_options');
        }
        if ($this->waitMs < 0 || $this->waitMs > self::MAX_WAIT_MS) {
            throw new InvalidRequest('wait_ms must be 0-'.self::MAX_WAIT_MS.'.', 'invalid_options');
        }
        if ($this->ttlSeconds < 0 || $this->ttlSeconds > self::MAX_TTL_SECONDS) {
            throw new InvalidRequest('ttl_seconds must be 0-'.self::MAX_TTL_SECONDS.'.', 'invalid_options');
        }
        if ($this->preserveScripts && ! in_array($this->strip, [StripMode::Conservative, StripMode::None], true)) {
            throw new InvalidRequest('preserve_scripts is only valid with the conservative or none strip modes.', 'invalid_options');
        }

        if ($this->strip === StripMode::XPath) {
            if ($this->keepXpaths === []) {
                throw new InvalidRequest('strip=xpath requires keep_xpaths.', 'invalid_xpath');
            }
            if (count($this->keepXpaths) > self::MAX_XPATHS) {
                throw new InvalidRequest('At most '.self::MAX_XPATHS.' keep_xpaths are allowed.', 'too_many_xpaths');
            }
            foreach ($this->keepXpaths as $name => $expression) {
                if (! is_string($name) || preg_match('/^[a-z0-9_]{1,32}$/', $name) !== 1) {
                    throw new InvalidRequest('keep_xpaths names must match [a-z0-9_]{1,32}.', 'invalid_xpath');
                }
                if (! is_string($expression) || trim($expression) === '' || strlen($expression) > self::MAX_XPATH_LENGTH) {
                    throw new InvalidRequest("keep_xpaths['{$name}'] must be 1-".self::MAX_XPATH_LENGTH.' characters.', 'invalid_xpath');
                }
            }
        } elseif ($this->keepXpaths !== []) {
            throw new InvalidRequest('keep_xpaths is only valid with strip=xpath.', 'invalid_options');
        }
    }
}
