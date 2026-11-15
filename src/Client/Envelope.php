<?php

declare(strict_types=1);

namespace Pluck\Client;

/**
 * The typed success/outcome envelope of PROXY_CONTRACT §3.
 *
 * Construction is fail-closed: a malformed answer raises {@see PluckException}
 * and is never turned into an empty "success". Content accessors refuse to
 * answer unless the outcome is {@see Outcome::Fetched}.
 */
final readonly class Envelope
{
    public const SUPPORTED_CONTRACT_VERSION = 1;

    /**
     * @param list<array<string,mixed>>        $jsonLd
     * @param array<string,list<string>>|null  $fragments
     * @param array<string,int>|null           $keptCounts
     * @param array<string,mixed>              $meta
     */
    private function __construct(
        public int $contractVersion,
        public string $requestId,
        public Outcome $outcome,
        public ?int $shopStatus,
        public ?string $finalUrl,
        public ?string $title,
        public ?string $html,
        public array $jsonLd,
        public ?array $fragments,
        public ?array $keptCounts,
        public ?int $retryAfterSeconds,
        public ?string $error,
        public array $meta,
    ) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $version = $data['contract_version'] ?? null;
        if ($version !== self::SUPPORTED_CONTRACT_VERSION) {
            throw new PluckException('Unsupported or missing contract_version: '.(is_scalar($version) ? (string) $version : gettype($version)).'.');
        }

        $outcome = is_string($data['outcome'] ?? null) ? Outcome::tryFrom($data['outcome']) : null;
        if ($outcome === null) {
            throw new PluckException('The proxy answer has an unknown or missing outcome.');
        }

        $requestId = $data['request_id'] ?? null;
        if (! is_string($requestId) || $requestId === '') {
            throw new PluckException('The proxy answer has no request_id.');
        }

        $html = self::optionalString($data, 'html');
        $fragments = self::fragments($data['fragments'] ?? null);
        $keptCounts = self::keptCounts($data['kept_counts'] ?? null);
        $jsonLd = self::jsonLd($data['json_ld'] ?? []);

        if ($outcome === Outcome::Fetched && $html === null && $fragments === null) {
            throw new PluckException("A 'fetched' answer must carry either html or fragments (request {$requestId}).");
        }
        if ($fragments !== null && $keptCounts === null) {
            throw new PluckException("A 'fetched' answer with fragments must carry kept_counts (request {$requestId}).");
        }
        if ($outcome !== Outcome::Fetched && ($html !== null || $fragments !== null)) {
            // Content next to a non-fetched outcome would invite "parse it anyway".
            throw new PluckException("A '{$outcome->value}' answer must not carry content (request {$requestId}).");
        }

        $meta = $data['meta'] ?? [];

        return new self(
            contractVersion: $version,
            requestId: $requestId,
            outcome: $outcome,
            shopStatus: self::optionalInt($data, 'shop_status'),
            finalUrl: self::optionalString($data, 'final_url'),
            title: self::optionalString($data, 'title'),
            html: $html,
            jsonLd: $jsonLd,
            fragments: $fragments,
            keptCounts: $keptCounts,
            retryAfterSeconds: self::optionalInt($data, 'retry_after_seconds'),
            error: self::optionalString($data, 'error'),
            meta: is_array($meta) ? $meta : [],
        );
    }

    public function isFetched(): bool
    {
        return $this->outcome === Outcome::Fetched;
    }

    /** @throws PluckException unless the outcome is fetched */
    public function html(): string
    {
        $this->assertFetched();

        return $this->html ?? throw new PluckException('This answer carries fragments, not html (strip=xpath).');
    }

    /**
     * @return list<string>
     * @throws PluckException unless the outcome is fetched and the name was requested
     */
    public function fragment(string $name): array
    {
        $this->assertFetched();

        if ($this->fragments === null || ! array_key_exists($name, $this->fragments)) {
            throw new PluckException("No fragment named '{$name}' in this answer.");
        }

        return $this->fragments[$name];
    }

    /**
     * Names whose expression matched nothing. A stale XPath is an
     * extraction-health signal for the consumer — never "no data", never "blocked".
     *
     * @return list<string>
     */
    public function staleExpressions(): array
    {
        return $this->keptCounts === null
            ? []
            : array_values(array_keys(array_filter($this->keptCounts, static fn (int $count): bool => $count === 0)));
    }

    public function cacheStatus(): ?string
    {
        $cache = $this->meta['cache'] ?? null;

        return is_string($cache) ? $cache : null;
    }

    private function assertFetched(): void
    {
        if (! $this->isFetched()) {
            throw new PluckException("Refusing to read content of a '{$this->outcome->value}' answer (request {$this->requestId}); only 'fetched' may produce data.");
        }
    }

    /** @param array<string,mixed> $data */
    private static function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new PluckException("Field '{$key}' must be a string.");
        }

        return $value;
    }

    /** @param array<string,mixed> $data */
    private static function optionalInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_int($value)) {
            throw new PluckException("Field '{$key}' must be an integer.");
        }

        return $value;
    }

    /** @return list<array<string,mixed>> */
    private static function jsonLd(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new PluckException("Field 'json_ld' must be a list.");
        }
        foreach ($value as $item) {
            if (! is_array($item)) {
                throw new PluckException("Every 'json_ld' entry must be an object.");
            }
        }

        return $value;
    }

    /** @return array<string,list<string>>|null */
    private static function fragments(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value)) {
            throw new PluckException("Field 'fragments' must be an object.");
        }
        foreach ($value as $name => $nodes) {
            if (! is_string($name) || ! is_array($nodes) || ! array_is_list($nodes)) {
                throw new PluckException("Every fragment must be a list of strings.");
            }
            foreach ($nodes as $node) {
                if (! is_string($node)) {
                    throw new PluckException("Every fragment node must be a string.");
                }
            }
        }

        return $value;
    }

    /** @return array<string,int>|null */
    private static function keptCounts(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value)) {
            throw new PluckException("Field 'kept_counts' must be an object.");
        }
        foreach ($value as $name => $count) {
            if (! is_string($name) || ! is_int($count) || $count < 0) {
                throw new PluckException("Every kept_counts entry must be a non-negative integer.");
            }
        }

        return $value;
    }
}
