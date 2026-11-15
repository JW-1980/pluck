<?php

declare(strict_types=1);

namespace Pluck\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pluck\Client\Envelope;
use Pluck\Client\Outcome;
use Pluck\Client\PluckException;
use Pluck\Tests\Support\Http;

final class EnvelopeTest extends TestCase
{
    public function test_fetched_html_envelope(): void
    {
        $e = Envelope::fromArray(Http::envelope(['json_ld' => [['@type' => 'Product']]]));

        $this->assertSame(Outcome::Fetched, $e->outcome);
        $this->assertSame('<div>ok</div>', $e->html());
        $this->assertSame([['@type' => 'Product']], $e->jsonLd);
        $this->assertSame('miss', $e->cacheStatus());
    }

    public function test_zero_matches_stays_fetched_and_is_reported_as_a_stale_expression(): void
    {
        $e = Envelope::fromArray(Http::envelope([
            'html' => null,
            'fragments' => ['price' => [], 'title' => ['<h1>x</h1>']],
            'kept_counts' => ['price' => 0, 'title' => 1],
        ]));

        $this->assertTrue($e->isFetched());
        $this->assertSame(['price'], $e->staleExpressions());
        $this->assertSame([], $e->fragment('price'));
        $this->assertSame(['<h1>x</h1>'], $e->fragment('title'));
    }

    public function test_html_of_an_xpath_answer_is_refused_not_empty(): void
    {
        $e = Envelope::fromArray(Http::envelope(['html' => null, 'fragments' => ['a' => ['<i/>']], 'kept_counts' => ['a' => 1]]));

        $this->expectException(PluckException::class);
        $e->html();
    }

    public function test_unknown_fragment_name_is_an_error_not_an_empty_list(): void
    {
        $e = Envelope::fromArray(Http::envelope(['html' => null, 'fragments' => ['a' => []], 'kept_counts' => ['a' => 0]]));

        $this->expectException(PluckException::class);
        $e->fragment('typo');
    }

    #[DataProvider('nonFetched')]
    public function test_only_fetched_may_produce_data(string $outcome): void
    {
        $e = Envelope::fromArray(Http::envelope(['outcome' => $outcome, 'html' => null, 'retry_after_seconds' => 600]));

        $this->assertFalse($e->outcome->mayParseContent());
        $this->assertSame(600, $e->retryAfterSeconds);

        $this->expectException(PluckException::class);
        $this->expectExceptionMessage("only 'fetched' may produce data");
        $e->html();
    }

    /** @return iterable<string,array{0:string}> */
    public static function nonFetched(): iterable
    {
        yield 'blocked' => ['blocked'];
        yield 'shop_error' => ['shop_error'];
        yield 'upstream_error' => ['upstream_error'];
    }

    /** @return iterable<string,array{0:array<string,mixed>}> */
    public static function malformed(): iterable
    {
        yield 'no version' => [['contract_version' => null]];
        yield 'future version' => [['contract_version' => 2]];
        yield 'no outcome' => [['outcome' => null]];
        yield 'unknown outcome' => [['outcome' => 'success']];
        yield 'no request id' => [['request_id' => '']];
        yield 'fetched without content' => [['html' => null]];
        yield 'blocked with content' => [['outcome' => 'blocked']];
        yield 'fragments without counts' => [['html' => null, 'fragments' => ['a' => []]]];
        yield 'negative count' => [['html' => null, 'fragments' => ['a' => []], 'kept_counts' => ['a' => -1]]];
        yield 'fragment node not a string' => [['html' => null, 'fragments' => ['a' => [1]], 'kept_counts' => ['a' => 1]]];
        yield 'json_ld not a list' => [['json_ld' => ['@type' => 'Product']]];
        yield 'json_ld entry scalar' => [['json_ld' => ['x']]];
        yield 'status not int' => [['shop_status' => '200']];
    }

    /** @param array<string,mixed> $override */
    #[DataProvider('malformed')]
    public function test_malformed_answers_fail_closed(array $override): void
    {
        $this->expectException(PluckException::class);
        Envelope::fromArray($override + Http::envelope());
    }
}
