<?php

namespace App\Tests\Service\Media;

use App\Entity\ServiceInstance;
use App\Service\Cache\StaleWhileRevalidateCache;
use App\Service\Media\BazarrClient;
use App\Service\Media\BazarrSubtitleIndex;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[AllowMockObjectsWithoutExpectations]
class BazarrSubtitleIndexSwrTest extends TestCase
{
    /** @var list<object> */
    private array $dispatched = [];

    private function swr(ArrayAdapter $pool): StaleWhileRevalidateCache
    {
        $bus = new class($this->dispatched) implements MessageBusInterface {
            /** @param list<object> $sink */
            public function __construct(private array &$sink) {}
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->sink[] = $message;
                return new Envelope($message);
            }
        };

        return new StaleWhileRevalidateCache($pool, $pool, $bus, new NullLogger());
    }

    private function instances(int $radarr = 1, int $sonarr = 1): ServiceInstanceProvider
    {
        $p = $this->createMock(ServiceInstanceProvider::class);
        $p->method('hasExactlyOneEnabled')->willReturnCallback(
            static fn (string $type) => ($type === ServiceInstance::TYPE_RADARR ? $radarr : $sonarr) === 1,
        );

        return $p;
    }

    private function index(BazarrClient $client, ArrayAdapter $pool, int $radarr = 1): BazarrSubtitleIndex
    {
        return new BazarrSubtitleIndex($client, $pool, $this->instances($radarr), $this->swr($pool), new NullLogger());
    }

    public function testAHardMissNeverCallsBazarrAndAnswersPending(): void
    {
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->never())->method('getMovies');

        $status = $this->index($client, new ArrayAdapter())->movieStatus(42);

        $this->assertSame('pending', $status['state']);
        $this->assertSame(0, $status['count']);
    }

    public function testAHardMissRequestsExactlyOneRefreshPerKey(): void
    {
        $pool  = new ArrayAdapter();
        $index = $this->index($this->createMock(BazarrClient::class), $pool);

        $index->movieStatus(1);
        $index->movieStatus(2);
        $index->movieStatus(3);

        $this->assertCount(1, $this->dispatched, 'the coalescing marker must collapse a whole grid to one request');
    }

    public function testAFreshEntryIsServedWithoutAnyRefreshRequest(): void
    {
        $pool = new ArrayAdapter();
        $this->swr($pool)->write(BazarrSubtitleIndex::KEY_MOVIES, [42 => ['state' => 'missing', 'count' => 3]], BazarrSubtitleIndex::HARD_TTL);
        $this->swr($pool)->write(BazarrSubtitleIndex::KEY_MOVIE_LANGS, [], BazarrSubtitleIndex::HARD_TTL);

        $status = $this->index($this->createMock(BazarrClient::class), $pool)->movieStatus(42);

        $this->assertSame(['state' => 'missing', 'count' => 3], $status);
        $this->assertSame([], $this->dispatched);
    }

    public function testAStaleEntryIsStillServedAndRequestsARefresh(): void
    {
        $pool = new ArrayAdapter();
        $swr  = $this->swr($pool);
        $swr->write(BazarrSubtitleIndex::KEY_MOVIES, [42 => ['state' => 'missing', 'count' => 3]], BazarrSubtitleIndex::HARD_TTL, time() - 300);
        $swr->write(BazarrSubtitleIndex::KEY_MOVIE_LANGS, [], BazarrSubtitleIndex::HARD_TTL, time() - 300);
        $this->dispatched = [];

        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->never())->method('getMovies');

        $status = $this->index($client, $pool)->movieStatus(42);

        $this->assertSame('missing', $status['state'], 'stale data is still shown');
        $this->assertCount(1, $this->dispatched);
    }

    public function testTheMultiInstanceGateAnswersHiddenAndRequestsNothing(): void
    {
        $index = $this->index($this->createMock(BazarrClient::class), new ArrayAdapter(), radarr: 2);

        $this->assertSame('hidden', $index->movieStatus(42)['state']);
        $this->assertSame([], $this->dispatched, 'a gated install must not spend a Bazarr fetch');
    }

    public function testAnIdAbsentFromAFreshMapIsHiddenNotPending(): void
    {
        $pool = new ArrayAdapter();
        $this->swr($pool)->write(BazarrSubtitleIndex::KEY_MOVIES, [1 => ['state' => 'complete', 'count' => 0]], BazarrSubtitleIndex::HARD_TTL);
        $this->swr($pool)->write(BazarrSubtitleIndex::KEY_MOVIE_LANGS, [], BazarrSubtitleIndex::HARD_TTL);

        $this->assertSame('hidden', $this->index($this->createMock(BazarrClient::class), $pool)->movieStatus(999)['state']);
    }

    public function testSeriesStatusFollowsTheSameContract(): void
    {
        $pool   = new ArrayAdapter();
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->never())->method('getSeries');

        $this->assertSame('pending', $this->index($client, $pool)->seriesStatus(7)['state']);
    }

    public function testResetClearsEveryPerRequestField(): void
    {
        $index = $this->index($this->createMock(BazarrClient::class), new ArrayAdapter());
        $index->movieStatus(1);
        $index->reset();

        $r = new \ReflectionObject($index);
        foreach (['movies', 'movieLangs', 'series', 'radarrGate', 'sonarrGate'] as $field) {
            $this->assertNull($r->getProperty($field)->getValue($index), $field . ' must be null after reset()');
        }
    }
}
