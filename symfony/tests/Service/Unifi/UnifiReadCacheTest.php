<?php

namespace App\Tests\Service\Unifi;

use App\Service\Unifi\UnifiReadCache;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class UnifiReadCacheTest extends TestCase
{
    public function testServesTheValueInsideTheTtlOnly(): void
    {
        $cache = new UnifiReadCache(new ArrayAdapter());
        $stub  = new StubUnifiFetcher();
        $cache->put($stub, 'live', 10.0, 1000.0, ['wan' => 1]);

        $this->assertSame(['wan' => 1], $cache->get($stub, 'live', 10.0, 1009.9));
        $this->assertNull($cache->get($stub, 'live', 10.0, 1010.0), 'expired at exactly the TTL');
        $this->assertNull($cache->get($stub, 'live', 10.0, 999.0), 'a clock that went backwards is a miss');
    }

    public function testGroupsAndConsolesAreSeparate(): void
    {
        $cache = new UnifiReadCache(new ArrayAdapter());
        $stub  = new StubUnifiFetcher();
        $cache->put($stub, 'live', 10.0, 1000.0, ['wan' => 1]);

        $this->assertNull($cache->get($stub, 'map', 10.0, 1001.0));
        $other = new StubUnifiFetcher();
        $other->identity = 'another-console';
        $this->assertNull($cache->get($other, 'live', 10.0, 1001.0));
    }

    public function testAnUnconfiguredFetcherIsNeverCached(): void
    {
        $pool  = new ArrayAdapter();
        $cache = new UnifiReadCache($pool);
        $stub  = new StubUnifiFetcher();
        $stub->identity = '';
        $cache->put($stub, 'live', 10.0, 1000.0, ['wan' => 1]);

        $this->assertNull($cache->get($stub, 'live', 10.0, 1001.0));
        $this->assertSame([], $pool->getValues());
    }

    public function testAMalformedEntryIsAMiss(): void
    {
        $pool  = new ArrayAdapter();
        $cache = new UnifiReadCache($pool);
        $stub  = new StubUnifiFetcher();
        $cache->put($stub, 'live', 10.0, 1000.0, ['wan' => 1]);
        $key = array_key_first($pool->getValues());
        $this->assertIsString($key);
        $pool->save($pool->getItem($key)->set('not-an-entry'));

        $this->assertNull($cache->get($stub, 'live', 10.0, 1001.0));
    }
}
