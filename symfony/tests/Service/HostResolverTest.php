<?php

namespace App\Tests\Service;

use App\Service\HealthService;
use App\Service\HostResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * The SSRF guard resolves hostnames on nearly every outbound call. A hung
 * resolver (Pi-hole down) used to block a PHP worker for the full resolver
 * timeout on EVERY such call — these tests pin the bounded-cost behaviour.
 */
class HostResolverTest extends TestCase
{
    protected function setUp(): void
    {
        HostResolver::usePool(null);
        HostResolver::reset();
    }

    protected function tearDown(): void
    {
        HostResolver::setResolver(null);
        HostResolver::usePool(null);
        HostResolver::reset();
    }

    public function testRepeatedLookupsOfOneHostResolveOnlyOnce(): void
    {
        $calls = 0;
        HostResolver::setResolver(function (string $h) use (&$calls) {
            $calls++;
            return ['192.168.1.10'];
        });

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(['192.168.1.10'], HostResolver::resolve('radarr.lan'));
        }
        $this->assertSame(1, $calls);
    }

    public function testFailedResolutionIsCachedToo(): void
    {
        $calls = 0;
        HostResolver::setResolver(function (string $h) use (&$calls) {
            $calls++;
            return false; // what gethostbynamel() returns on failure
        });

        $this->assertSame([], HostResolver::resolve('dead.lan'));
        $this->assertSame([], HostResolver::resolve('dead.lan'));
        $this->assertSame(1, $calls, 'a failing/hung resolver must not be re-queried on every call');
    }

    public function testLookupsAreKeyedByHostCaseInsensitively(): void
    {
        $calls = 0;
        HostResolver::setResolver(function (string $h) use (&$calls) {
            $calls++;
            return ['10.0.0.1'];
        });

        HostResolver::resolve('Sonarr.LAN');
        HostResolver::resolve('sonarr.lan');
        $this->assertSame(1, $calls);
        HostResolver::resolve('other.lan');
        $this->assertSame(2, $calls);
    }

    public function testMemoEntryExpiresAfterTtl(): void
    {
        $now = 1000.0;
        HostResolver::setClock(function () use (&$now) { return $now; });
        $answers = [['10.0.0.1'], ['10.0.0.2']];
        HostResolver::setResolver(function (string $h) use (&$answers) {
            return array_shift($answers);
        });

        $this->assertSame(['10.0.0.1'], HostResolver::resolve('a.lan'));
        $now += HostResolver::TTL - 1;
        $this->assertSame(['10.0.0.1'], HostResolver::resolve('a.lan'));
        $now += 2;
        $this->assertSame(['10.0.0.2'], HostResolver::resolve('a.lan'), 'a changed DNS answer must be picked up after the TTL');

        HostResolver::setClock(null);
    }

    public function testSlowResolutionIsRememberedLonger(): void
    {
        $now = 1000.0;
        HostResolver::setClock(function () use (&$now) { return $now; });
        $calls = 0;
        HostResolver::setResolver(function (string $h) use (&$now, &$calls) {
            $calls++;
            $now += HostResolver::SLOW_AFTER + 1; // the lookup "took" long
            return false;
        });

        HostResolver::resolve('slow.lan');
        $now += HostResolver::TTL + 5; // past the normal TTL
        HostResolver::resolve('slow.lan');
        $this->assertSame(1, $calls, 'a host that just hung the resolver must not be retried after only the short TTL');

        $now += HostResolver::SLOW_TTL;
        HostResolver::resolve('slow.lan');
        $this->assertSame(2, $calls);

        HostResolver::setClock(null);
    }

    public function testMemoIsBounded(): void
    {
        HostResolver::setResolver(fn (string $h) => ['10.0.0.1']);
        for ($i = 0; $i < HostResolver::MAX_ENTRIES * 3; $i++) {
            HostResolver::resolve("h$i.lan");
        }
        $this->assertLessThanOrEqual(HostResolver::MAX_ENTRIES, HostResolver::memoSize());
    }

    public function testSharedPoolServesAFreshProcess(): void
    {
        $pool = new ArrayAdapter();
        HostResolver::usePool($pool);
        $calls = 0;
        HostResolver::setResolver(function (string $h) use (&$calls) {
            $calls++;
            return ['10.1.1.1'];
        });

        HostResolver::resolve('shared.lan');
        HostResolver::reset(); // simulates a new PHP process: memo gone, pool survives
        $this->assertSame(['10.1.1.1'], HostResolver::resolve('shared.lan'));
        $this->assertSame(1, $calls, 'classic (non-worker) mode relies on the shared cache across requests');
    }

    public function testSharedPoolCachesFailuresToo(): void
    {
        HostResolver::usePool(new ArrayAdapter());
        $calls = 0;
        HostResolver::setResolver(function (string $h) use (&$calls) {
            $calls++;
            return false;
        });

        HostResolver::resolve('dead.lan');
        HostResolver::reset();
        $this->assertSame([], HostResolver::resolve('dead.lan'));
        $this->assertSame(1, $calls);
    }

    // ─── urlBlockedReason keeps its SSRF semantics on top of the cache ───

    public function testUrlBlockedReasonStillBlocksHostnameResolvingToMetadataIp(): void
    {
        HostResolver::setResolver(fn (string $h) => ['169.254.169.254']);
        $this->assertSame('link-local', HealthService::urlBlockedReason('http://evil.example/'));
        // …and from the cache the second time round.
        $this->assertSame('link-local', HealthService::urlBlockedReason('http://evil.example/'));
    }

    public function testUrlBlockedReasonAllowsWhenResolutionFails(): void
    {
        // Existing behaviour: an unresolvable host is NOT blocked (curl will
        // simply fail to connect). The cache must not change that.
        HostResolver::setResolver(fn (string $h) => false);
        $this->assertNull(HealthService::urlBlockedReason('http://nxdomain.example/'));
        $this->assertNull(HealthService::urlBlockedReason('http://nxdomain.example/'));
    }

    public function testUrlBlockedReasonSkipsDnsForIpLiterals(): void
    {
        $calls = 0;
        HostResolver::setResolver(function (string $h) use (&$calls) {
            $calls++;
            return [];
        });
        HealthService::urlBlockedReason('http://192.168.1.50:7878/');
        HealthService::urlBlockedReason('http://[::1]:8080/');
        HealthService::urlBlockedReason('http://169.254.169.254./');
        $this->assertSame(0, $calls);
    }

    public function testUrlBlockedReasonResolvesAHostOncePerWindow(): void
    {
        $calls = 0;
        HostResolver::setResolver(function (string $h) use (&$calls) {
            $calls++;
            return ['10.0.0.10'];
        });
        for ($i = 0; $i < 10; $i++) {
            $this->assertNull(HealthService::urlBlockedReason('http://bazarr.lan:6767/api'));
        }
        $this->assertSame(1, $calls);
    }
}
