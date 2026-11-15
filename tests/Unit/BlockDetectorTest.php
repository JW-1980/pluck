<?php

declare(strict_types=1);

namespace Pluck\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pluck\Support\BlockDetector;

final class BlockDetectorTest extends TestCase
{
    /** @return iterable<string,array{0:int,1:?string,2:bool,3:?string}> status, body, blocked?, signal */
    public static function cases(): iterable
    {
        yield 'plain 200 page' => [200, '<html><body>Magnesium 400mg</body></html>', false, null];
        yield 'akamai access denied on 403' => [403, '<HTML><TITLE>Access Denied</TITLE></HTML>', true, 'access denied'];
        yield 'cloudflare interstitial served as 200' => [200, '<title>Just a moment...</title>', true, 'just a moment'];
        yield 'bare 403' => [403, 'Forbidden', true, 'HTTP 403'];
        yield 'bare 429' => [429, '', true, 'HTTP 429'];
        yield 'cloudflare edge 522' => [522, 'timeout', true, 'HTTP 522'];
        yield 'null body 503' => [503, null, true, 'HTTP 503'];
        yield 'incapsula in a 200' => [200, '<iframe src="/_Incapsula_Resource?x">', true, '_incapsula_resource'];
        yield '404 with templated access denied is NOT a block' => [404, '<h1>Access Denied</h1>', false, null];
        yield '410 is NOT a block' => [410, 'Just a moment', false, null];
        yield '500 is not a block' => [500, 'oops', false, null];
        yield '401 is a credentials problem, not a block' => [401, 'login', false, null];
    }

    #[DataProvider('cases')]
    public function test_verdicts(int $status, ?string $body, bool $blocked, ?string $signal): void
    {
        $verdict = (new BlockDetector())->evaluate($status, $body);

        $this->assertSame($blocked, $verdict->blocked);
        $this->assertSame($signal, $verdict->signal);
    }

    public function test_marker_verdict_names_the_vendor_and_beats_the_bare_status(): void
    {
        $verdict = (new BlockDetector())->evaluate(403, '<p>Cloudflare Ray ID: abc</p>');

        $this->assertSame('cloudflare ray id', $verdict->signal);
    }

    public function test_only_the_first_64k_are_scanned(): void
    {
        $detector = new BlockDetector();
        $late = str_repeat('a', BlockDetector::SCAN_CHAR_LIMIT).'access denied';

        $this->assertFalse($detector->evaluate(200, $late)->blocked);
        $this->assertTrue($detector->evaluate(200, substr($late, 100, BlockDetector::SCAN_CHAR_LIMIT - 20).'access denied')->blocked);
    }

    public function test_a_proven_genuine_document_skips_marker_scanning_but_not_status(): void
    {
        $detector = new BlockDetector();

        $this->assertFalse($detector->evaluate(200, 'Our access denied policy page', genuineDocument: true)->blocked);
        $this->assertTrue($detector->evaluate(403, 'Our access denied policy page', genuineDocument: true)->blocked);
    }
}
