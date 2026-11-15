<?php

declare(strict_types=1);

namespace Pluck\Http;

use Psr\Http\Message\ResponseInterface;

/** Reads a streamed body and abandons it the moment it passes the byte cap. */
final class BoundedBody
{
    private const CHUNK = 65_536;

    /** @throws FetchFailed */
    public static function read(ResponseInterface $response, int $maxBytes): string
    {
        $declared = $response->getHeaderLine('Content-Length');
        if ($declared !== '' && ctype_digit($declared) && (int) $declared > $maxBytes) {
            throw new FetchFailed("The page is larger than {$maxBytes} bytes.", 'content_too_large');
        }

        $stream = $response->getBody();
        $body = '';

        try {
            if ($stream->isSeekable()) {
                $stream->rewind();
            }

            while (! $stream->eof()) {
                $body .= $stream->read(self::CHUNK);
                if (strlen($body) > $maxBytes) {
                    throw new FetchFailed("The page is larger than {$maxBytes} bytes.", 'content_too_large');
                }
            }
        } catch (FetchFailed $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            throw new FetchFailed('Transport error while reading the body.', 'transport', $e);
        } finally {
            $stream->close();
        }

        return $body;
    }
}
