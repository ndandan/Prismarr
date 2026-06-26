<?php

namespace App\Tests\Service\Media;

use App\Service\Media\RadarrClient;
use App\Service\Media\ServiceHealthCache;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * multiGet() concurrency wiring. The happy path (live concurrent fetch
 * returning data) needs a real upstream and is covered by the controller
 * smoke test + manual verification; here we lock the deterministic,
 * no-network contract: an open circuit breaker short-circuits the whole
 * batch to nulls without ever touching the network or the instance
 * provider.
 */
#[AllowMockObjectsWithoutExpectations]
class RadarrClientMultiGetTest extends TestCase
{
    public function testOpenCircuitBreakerShortCircuitsWholeBatchToNulls(): void
    {
        $health = new ServiceHealthCache(new ArrayAdapter());
        $health->markDown('radarr'); // unkeyed: matches the default (null-slug) instance

        // The instance provider must never be consulted — the breaker check
        // happens before ensureConfig(). A real-but-unconfigured provider mock
        // proves we never reached config resolution.
        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->expects($this->never())->method('getDefault');

        $client = new RadarrClient($instances, new NullLogger(), $health);

        $result = $client->multiGet([
            'status' => ['path' => '/api/v3/system/status'],
            'queue'  => ['path' => '/api/v3/queue'],
        ]);

        $this->assertSame(['status' => null, 'queue' => null], $result);
    }

    public function testEmptyRequestListReturnsEmptyArray(): void
    {
        $health    = new ServiceHealthCache(new ArrayAdapter());
        $instances = $this->createMock(ServiceInstanceProvider::class);
        $client    = new RadarrClient($instances, new NullLogger(), $health);

        $this->assertSame([], $client->multiGet([]));
    }
}
