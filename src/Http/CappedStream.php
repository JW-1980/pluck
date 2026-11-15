<?php

declare(strict_types=1);

namespace Pluck\Http;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * Response sink that refuses to grow past a byte cap. Guzzle writes the
 * DECODED body into it as the transfer runs, so an oversized page — including a
 * gzip bomb whose compressed size looks harmless — is abandoned mid-transfer.
 *
 * How the abort works (measured, not assumed): PHP defers an exception thrown
 * inside a cURL write callback until the whole transfer has finished, so
 * throwing here would NOT stop the download. A short write (returning 0) makes
 * cURL abort at once with a write error. The first overflowing write therefore
 * returns 0 and sets {@see exceeded()}; the caller maps the resulting transport
 * error to `content_too_large`. A handler that ignores short writes (PHP's
 * stream handler) writes again, and that second write throws.
 */
final class CappedStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private StreamInterface $stream;

    private int $written = 0;

    private bool $exceeded = false;

    public function __construct(private readonly int $maxBytes)
    {
        $this->stream = Utils::streamFor(fopen('php://temp/maxmemory:'.(1024 * 1024), 'r+'));
    }

    public function exceeded(): bool
    {
        return $this->exceeded;
    }

    public function write(string $string): int
    {
        if ($this->exceeded) {
            throw $this->tooLarge();
        }

        if ($this->written + strlen($string) > $this->maxBytes) {
            $this->exceeded = true;

            return 0;
        }

        $this->written += strlen($string);

        return $this->stream->write($string);
    }

    public function tooLarge(): FetchFailed
    {
        return new FetchFailed("The page is larger than {$this->maxBytes} bytes.", 'content_too_large');
    }
}
