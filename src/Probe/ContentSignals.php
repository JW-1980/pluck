<?php

declare(strict_types=1);

namespace Pluck\Probe;

/**
 * Cheap, evidence-only facts about a fetched page: does it look like a
 * server-rendered product page or an empty JavaScript shell? (Inktomi's
 * extraction order starts with JSON-LD Product, then OpenGraph/microdata.)
 */
final readonly class ContentSignals
{
    /** Visible text below this many characters, with a mount node present, reads as an SPA shell. */
    public const SHELL_TEXT_CHARS = 500;

    public function __construct(
        public int $bytes,
        public bool $jsonLdProduct,
        public bool $priceMarkup,
        public int $visibleTextChars,
        public bool $mountNode,
        public bool $javascriptNotice = false,
    ) {}

    public static function analyse(string $html): self
    {
        $jsonLdProduct = false;
        if (preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $blocks) > 0) {
            foreach ($blocks[1] as $block) {
                if (preg_match('/"@type"\s*:\s*(\[[^\]]*)?"Product"/i', $block) === 1) {
                    $jsonLdProduct = true;
                    break;
                }
            }
        }

        // Price evidence counts in the document and in JSON data scripts, NOT in plain JavaScript
        // bodies: an SPA bundle that merely contains the text `itemprop="price"` is still a shell.
        $priceMarkup = false;
        if (preg_match_all('#<script\b([^>]*)>(.*?)</script>#is', $html, $scripts, PREG_SET_ORDER) > 0) {
            foreach ($scripts as $script) {
                if (preg_match('/type=["\'][^"\']*json[^"\']*["\']/i', $script[1]) === 1 && preg_match('/"price"\s*:|"priceCurrency"/i', $script[2]) === 1) {
                    $priceMarkup = true;
                    break;
                }
            }
        }
        if (! $priceMarkup) {
            $outsideScripts = preg_replace('#<script\b.*?</script>#is', '', $html) ?? $html;
            $priceMarkup = preg_match('/itemprop=["\']price["\']|property=["\'](?:product|og):price/i', $outsideScripts) === 1;
        }

        $withoutCode = preg_replace(['#<script\b.*?</script>#is', '#<style\b.*?</style>#is', '#<noscript\b.*?</noscript>#is'], '', $html) ?? $html;
        $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($withoutCode), ENT_QUOTES | ENT_HTML5)));

        $mountNode = preg_match('/<div[^>]+id=["\'](?:root|app|__next|__nuxt|svelte)["\']|data-reactroot|ng-app|<app-root/i', $html) === 1;

        // Seen on real SPA shells (e.g. plus.nl, OutSystems): the page's only text is a <noscript>
        // "JavaScript is required". It is invisible to a JS-capable browser, so it is not counted
        // as visible text above, but it is exactly the evidence of a shell.
        $withNoscript = preg_replace(['#<script\b.*?</script>#is', '#<style\b.*?</style>#is'], '', $html) ?? $html;
        $javascriptNotice = preg_match('/javascript\s+(?:is\s+)?(?:required|vereist|nodig)|(?:enable|schakel)\s+javascript/i', strip_tags($withNoscript)) === 1;

        return new self(strlen($html), $jsonLdProduct, $priceMarkup, mb_strlen($text), $mountNode, $javascriptNotice);
    }

    public function hasProductData(): bool
    {
        return $this->jsonLdProduct || $this->priceMarkup;
    }

    public function looksLikeShell(): bool
    {
        return ! $this->hasProductData()
            && ($this->mountNode || $this->javascriptNotice)
            && $this->visibleTextChars < self::SHELL_TEXT_CHARS;
    }
}
