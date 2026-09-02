<?php

namespace App\Tests\Service\Cache;

use App\Message\RefreshCacheKey;
use App\Service\Cache\StaleWhileRevalidateCache;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class StaleWhileRevalidateCacheTest extends TestCase
{
    /** @var list<object> */
    private array $dispatched = [];

    private function bus(bool $throw = false): MessageBusInterface
    {
        return new class($this->dispatched, $throw) implements MessageBusInterface {
            /** @param list<object> $sink */
            public function __construct(private array &$sink, private bool $throw) {}
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                if ($this->throw) {
                    throw new \RuntimeException('transport down');
                }
                $this->sink[] = $message;
                return new Envelope($message);
            }
        };
    }

    private function swr(ArrayAdapter $pool, bool $throwingBus = false): StaleWhileRevalidateCache
    {
        return new StaleWhileRevalidateCache($pool, $pool, $this->bus($throwingBus), new NullLogger());
    }

    public function testReadReturnsNullOnHardMiss(): void
    {
        $this->assertNull($this->swr(new ArrayAdapter())->read('k', 60));
    }

    public function testWriteThenReadIsFresh(): void
    {
        $pool = new ArrayAdapter();
        $swr  = $this->swr($pool);
        $swr->write('k', ['a' => 1], 600);

        $this->assertSame(['value' => ['a' => 1], 'state' => 'fresh'], $swr->read('k', 60));
    }

    public function testEntryOlderThanSoftTtlReadsStale(): void
    {
        $pool = new ArrayAdapter();
        $swr  = $this->swr($pool);
        $swr->write('k', ['a' => 1], 600, time() - 120);

        $hit = $swr->read('k', 60);
        $this->assertNotNull($hit);
        $this->assertSame('stale', $hit['state']);
        $this->assertSame(['a' => 1], $hit['value']);
    }

    public function testGetOrComputeFetchesOnceThenServesFromCache(): void
    {
        $pool  = new ArrayAdapter();
        $swr   = $this->swr($pool);
        $calls = 0;
        $fetch = function () use (&$calls) { $calls++; return ['row']; };

        $first  = $swr->getOrCompute('k', 60, 600, $fetch);
        $second = $swr->getOrCompute('k', 60, 600, $fetch);

        $this->assertSame(['row'], $first['value']);
        $this->assertSame('fresh', $first['state']);
        $this->assertSame(['row'], $second['value']);
        $this->assertSame(1, $calls, 'second call must hit the cache, not re-fetch');
    }

    public function testGetOrComputeDoesNotStoreAnEmptyResult(): void
    {
        $pool  = new ArrayAdapter();
        $swr   = $this->swr($pool);
        $calls = 0;
        $fetch = function () use (&$calls) { $calls++; return []; };

        $swr->getOrCompute('k', 60, 600, $fetch);
        $swr->getOrCompute('k', 60, 600, $fetch);

        $this->assertSame(2, $calls, 'an empty result must expire immediately so the next load retries');
    }

    public function testRequestRefreshDispatchesOnceInsideTheMarkerWindow(): void
    {
        $pool = new ArrayAdapter();
        $swr  = $this->swr($pool);

        $swr->requestRefresh('k');
        $swr->requestRefresh('k');
        $swr->requestRefresh('k');

        $this->assertCount(1, $this->dispatched);
        $this->assertInstanceOf(RefreshCacheKey::class, $this->dispatched[0]);
        $this->assertSame('k', $this->dispatched[0]->key);
    }

    public function testDispatchFailureDropsTheMarkerSoTheNextRequestRetries(): void
    {
        $pool = new ArrayAdapter();
        $swr  = $this->swr($pool, throwingBus: true);

        $swr->requestRefresh('k'); // throws internally, must be swallowed

        $this->assertFalse(
            $pool->getItem('k.refreshing')->isHit(),
            'a failed dispatch must not leave the coalescing marker behind for 30 s',
        );
    }

    public function testWriteClearsTheOverdueRequestStamp(): void
    {
        $pool = new ArrayAdapter();
        $swr  = $this->swr($pool);

        $swr->requestRefresh('k');
        $this->assertTrue($pool->getItem('k.requested_at')->isHit());

        $swr->write('k', ['a' => 1], 600);
        $this->assertFalse($pool->getItem('k.requested_at')->isHit());
    }

    public function testRefreshIsOverdueWhenTheStampIsOlderThanTheThreshold(): void
    {
        $pool = new ArrayAdapter();
        $swr  = $this->swr($pool);

        $item = $pool->getItem('k.requested_at');
        $item->set(time() - 300)->expiresAfter(900);
        $pool->save($item);

        $this->assertTrue($swr->refreshIsOverdue('k', 180));
        $this->assertFalse($swr->refreshIsOverdue('k', 600));
    }

    public function testDeleteDropsValueMarkerAndStamp(): void
    {
        $pool = new ArrayAdapter();
        $swr  = $this->swr($pool);
        $swr->write('k', ['a' => 1], 600);
        $swr->requestRefresh('k');

        $swr->delete('k');

        $this->assertNull($swr->read('k', 60));
        $this->assertFalse($pool->getItem('k.refreshing')->isHit());
        $this->assertFalse($pool->getItem('k.requested_at')->isHit());
    }
}
