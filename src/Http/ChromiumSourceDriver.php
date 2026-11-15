<?php

declare(strict_types=1);

namespace Pluck\Http;

use Pluck\Client\InvalidRequest;
use Pluck\Client\Outcome;
use Pluck\Contracts\SourceDriver;
use Pluck\Support\BlockDetector;
use Pluck\Support\BlockedTarget;
use Pluck\Contracts\TargetValidator;
use Pluck\Support\TargetGuard;
use Pluck\Support\UserAgent;

/**
 * Headless Chromium `--dump-dom` rung — the mechanism WelZoLekker's
 * `PromotionPageFetcher` already uses for `requires_js` pages, same flags.
 *
 * This is a plain headless browser, NOT a stealth browser: it applies no
 * fingerprint patches. It exists so the probe (and small consumers) can tell
 * "needs JavaScript" from "needs an anti-bot solver". Real stealth rendering
 * belongs to the proxy's Playwright rung.
 */
final class ChromiumSourceDriver implements SourceDriver
{
    public function __construct(
        private readonly string $binary,
        private readonly TargetValidator $guard = new TargetGuard(),
        private readonly UserAgent $userAgents = new UserAgent(),
        private readonly BlockDetector $detector = new BlockDetector(),
        private readonly int $timeoutSeconds = PoliteFetcher::DEFAULT_TIMEOUT_SECONDS,
        private readonly int $maxBytes = PoliteFetcher::DEFAULT_MAX_BYTES,
        private readonly string $language = 'nl-NL',
    ) {}

    public function name(): string
    {
        return 'chromium';
    }

    public function fetch(string $url): SourceResult
    {
        try {
            $target = $this->guard->vet($url);
        } catch (BlockedTarget $e) {
            throw new InvalidRequest('Target refused: '.$e->getMessage(), 'private_target', $e);
        }

        $timeout = max(5, $this->timeoutSeconds);
        $command = [
            $this->binary,
            '--headless=new',
            '--no-sandbox',
            '--disable-gpu',
            '--disable-dev-shm-usage',
            '--hide-scrollbars',
            '--virtual-time-budget='.(max(1, $timeout - 5) * 1000),
            '--lang='.$this->language,
            '--user-agent='.$this->userAgents->forHost($target['host']),
            '--dump-dom',
            $url,
        ];

        $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            return new SourceResult(Outcome::UpstreamError, $this->name(), error: 'The browser binary could not be started.');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = '';
        $deadline = microtime(true) + $timeout;
        $timedOut = false;

        while (true) {
            $out .= (string) stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]); // drain stderr so the child cannot block on it
            $running = proc_get_status($process)['running'];

            if (strlen($out) > $this->maxBytes) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return new SourceResult(Outcome::ShopError, $this->name(), error: "content_too_large: the page is larger than {$this->maxBytes} bytes.");
            }
            if (! $running) {
                $out .= (string) stream_get_contents($pipes[1]);
                break;
            }
            if (microtime(true) > $deadline) {
                $timedOut = true;
                proc_terminate($process, 9);
                break;
            }
            usleep(50_000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if ($timedOut) {
            return new SourceResult(Outcome::UpstreamError, $this->name(), error: "The browser took longer than {$timeout} seconds.");
        }
        if (trim($out) === '') {
            return new SourceResult(Outcome::UpstreamError, $this->name(), error: 'The browser returned an empty page.');
        }

        // --dump-dom exposes no HTTP status; only the challenge-marker half of block detection applies.
        $verdict = $this->detector->evaluate(200, $out);
        if ($verdict->blocked) {
            return new SourceResult(Outcome::Blocked, $this->name(), finalUrl: $url, blockSignal: $verdict->signal);
        }

        return new SourceResult(Outcome::Fetched, $this->name(), null, $url, $out);
    }
}
