<?php

declare(strict_types=1);

namespace Pluck\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pluck\Support\BlockedTarget;
use Pluck\Support\TargetGuard;
use Pluck\Tests\Support\Http;

final class TargetGuardTest extends TestCase
{
    /** @return iterable<string,array{0:string}> */
    public static function nonPublicIps(): iterable
    {
        foreach (['127.0.0.1', '10.1.2.3', '172.16.0.1', '172.31.255.255', '192.168.1.1', '169.254.169.254', '100.64.0.1', '100.127.255.255', '0.0.0.0', '198.18.0.1', '224.0.0.1', '240.0.0.1', '::1', 'fe80::1', 'fc00::1', 'fd12::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1', '2001:db8::1'] as $ip) {
            yield $ip => [$ip];
        }
    }

    #[DataProvider('nonPublicIps')]
    public function test_non_public_addresses_are_rejected(string $ip): void
    {
        $this->assertFalse(TargetGuard::isPublicIp($ip));
    }

    /** @return iterable<string,array{0:string}> */
    public static function publicIps(): iterable
    {
        foreach (['93.184.216.34', '8.8.8.8', '100.63.255.255', '100.128.0.1', '172.15.0.1', '172.32.0.1', '2606:4700:4700::1111', '::ffff:8.8.8.8'] as $ip) {
            yield $ip => [$ip];
        }
    }

    #[DataProvider('publicIps')]
    public function test_public_addresses_pass(string $ip): void
    {
        $this->assertTrue(TargetGuard::isPublicIp($ip));
    }

    public function test_garbage_is_not_public(): void
    {
        $this->assertFalse(TargetGuard::isPublicIp('not-an-ip'));
        $this->assertFalse(TargetGuard::isPublicIp(''));
    }

    public function test_vet_returns_the_pinnable_target(): void
    {
        $target = Http::publicGuard('93.184.216.34')->vet('https://Shop.Example/p/1');

        $this->assertSame(['scheme' => 'https', 'host' => 'shop.example', 'port' => 443, 'ip' => '93.184.216.34'], $target);
    }

    /** @return iterable<string,array{0:string}> */
    public static function refused(): iterable
    {
        yield 'file scheme' => ['file:///etc/passwd'];
        yield 'gopher' => ['gopher://shop.example/'];
        yield 'credentials' => ['https://u:p@shop.example/'];
        yield 'localhost' => ['http://localhost/'];
        yield 'sub localhost' => ['http://api.localhost/'];
        yield 'internal tld' => ['http://db.internal/'];
        yield 'ip literal private' => ['http://192.168.0.10/admin'];
        yield 'ip literal metadata' => ['http://169.254.169.254/latest/meta-data'];
        yield 'ipv6 literal loopback' => ['http://[::1]/'];
        yield 'relative' => ['/p/1'];
    }

    #[DataProvider('refused')]
    public function test_unsafe_targets_are_refused_before_any_request(string $url): void
    {
        $this->expectException(BlockedTarget::class);
        Http::publicGuard()->vet($url);
    }

    public function test_a_hostname_resolving_to_a_private_address_is_refused(): void
    {
        $this->expectException(BlockedTarget::class);
        (new TargetGuard(fn () => ['10.0.0.5']))->vet('https://innocent.example/');
    }

    public function test_one_private_record_among_public_ones_poisons_the_host(): void
    {
        $this->expectException(BlockedTarget::class);
        (new TargetGuard(fn () => ['93.184.216.34', '127.0.0.1']))->vet('https://rebind.example/');
    }

    public function test_unresolvable_host_is_refused(): void
    {
        $this->expectException(BlockedTarget::class);
        (new TargetGuard(fn () => []))->vet('https://nx.example/');
    }
}
