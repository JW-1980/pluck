<?php

declare(strict_types=1);

namespace Pluck\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pluck\Http\CappedStream;
use Pluck\Http\FetchFailed;

final class CappedStreamTest extends TestCase
{
    public function test_accepts_up_to_and_including_the_cap_across_writes(): void
    {
        $stream = new CappedStream(10);
        $stream->write('12345');
        $stream->write('67890');
        $stream->rewind();

        $this->assertSame('1234567890', (string) $stream);
    }

    public function test_the_first_overflowing_write_aborts_with_a_short_write_and_sets_the_flag(): void
    {
        $stream = new CappedStream(10);
        $stream->write('1234567890');
        $this->assertFalse($stream->exceeded());

        $this->assertSame(0, $stream->write('1'), 'a short write is what makes cURL abort mid-transfer');
        $this->assertTrue($stream->exceeded());
    }

    public function test_a_handler_that_ignores_short_writes_gets_an_exception_on_the_next_write(): void
    {
        $stream = new CappedStream(3);
        $stream->write('abcd');

        try {
            $stream->write('e');
            $this->fail('Expected FetchFailed.');
        } catch (FetchFailed $e) {
            $this->assertSame('content_too_large', $e->reason);
        }
    }

    public function test_overflowing_content_is_never_stored(): void
    {
        $stream = new CappedStream(5);
        $stream->write('abc');
        $stream->write('defgh');
        $stream->rewind();

        $this->assertSame('abc', (string) $stream);
    }
}
