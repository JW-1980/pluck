<?php

declare(strict_types=1);

namespace Pluck\Support;

/**
 * User-agent policy (FUNCTIONAL_DESIGN §4 #10): a browser string for shop pages
 * (owner decision 2026-09-29 in the consumer repos), an identifying string for
 * identified bots such as Open Food Facts. Both overridable per consumer.
 */
final class UserAgent
{
    /** Chrome 124 on Windows — the profile Inktomi impersonates (`chrome124`). */
    public const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    /** Identifying UA Inktomi sends to Open Food Facts (src/superraten/config.py). */
    public const OPEN_FOOD_FACTS = 'InktomiSuperRaten/1.0';

    /** Identified-bot hosts that must not receive the browser string. */
    private const IDENTIFIED_BOT_HOSTS = ['openfoodfacts.org'];

    public function __construct(
        private readonly string $browser = self::BROWSER,
        private readonly string $identified = self::OPEN_FOOD_FACTS,
    ) {}

    public function forHost(string $host): string
    {
        $host = strtolower($host);
        foreach (self::IDENTIFIED_BOT_HOSTS as $bot) {
            if ($host === $bot || str_ends_with($host, '.'.$bot)) {
                return $this->identified;
            }
        }

        return $this->browser;
    }

    public function forUrl(string $url): string
    {
        return $this->forHost((string) parse_url($url, PHP_URL_HOST));
    }
}
