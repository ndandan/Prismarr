<?php

namespace App\Tests\Controller;

use App\Entity\Media\WokeometerTitle;
use App\Entity\Setting;
use App\Entity\User;
use App\Message\SyncWokeometerCatalog;
use App\Service\Cache\StaleWhileRevalidateCache;
use App\Service\Media\MediaLibraryCache;
use App\Service\Wokeometer\WokeometerSyncService;
use App\Tests\AbstractWebTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\TraceableMessageBus;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * POST /admin/settings/wokeometer/sync + GET .../state. These endpoints only
 * ever QUEUE a run on the `async` transport (in-memory under test, never
 * consumed here): the Wokeometer HTTP client is only reachable from the
 * worker, so `total_requests` must stay 0 whatever we do below.
 */
#[AllowMockObjectsWithoutExpectations]
final class AdminSettingsWokeometerSyncTest extends AbstractWebTestCase
{
    private const SYNC  = '/admin/settings/wokeometer/sync';
    private const STATE = '/admin/settings/wokeometer/state';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dropLibraryCache();
    }

    protected function tearDown(): void
    {
        $this->dropLibraryCache();
        parent::tearDown();
    }

    /** The shared library entries live in the FILESYSTEM cache.app pool: never let one test's warm cache leak into the next (or the next run). */
    private function dropLibraryCache(): void
    {
        $swr = static::getContainer()->get(StaleWhileRevalidateCache::class);
        $swr->delete('media.movies.radarr-1');
        $swr->delete('media.series.sonarr-1');
    }

    /**
     * Replace services for the NEXT request. The settings page (used to scrape
     * the CSRF token) has already initialised them in the current kernel, so
     * boot a fresh one, override, then stop the browser rebooting it.
     *
     * @param array<string, object> $services
     */
    private function overrideServices(array $services): void
    {
        $kernel = $this->client->getKernel();
        $kernel->shutdown();
        $kernel->boot();
        $container = $kernel->getContainer()->get('test.service_container');
        foreach ($services as $id => $service) {
            $container->set($id, $service);
        }
        $this->client->disableReboot();
    }

    private function seedKey(): void
    {
        $em = $this->em();
        $em->persist(new Setting('wokeometer_api_key', 'wok_testkey0123456789'));
        $em->flush();
    }

    /** Real CSRF token, scraped from the rendered card (exercises the whole chain). */
    private function csrf(): string
    {
        $crawler = $this->client->request('GET', '/admin/settings');
        $token   = (string) $crawler->filter('[data-wokeometer-sync]')->attr('data-csrf');
        self::assertNotSame('', $token);

        return $token;
    }

    /**
     * @param array<string, string> $extra
     * @return array<string, mixed>
     */
    private function post(string $token, array $extra = []): array
    {
        $this->client->request('POST', self::SYNC, ['_token' => $token] + $extra, [], ['HTTP_ACCEPT' => 'application/json']);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);

        return $data;
    }

    /** @return list<object> */
    private function queued(): array
    {
        $transport = $this->client->getKernel()->getContainer()->get('test.service_container')->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values(array_map(static fn ($e) => $e->getMessage(), $transport->getSent()));
    }

    private function totalRequests(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT total_requests FROM wokeometer_sync_state WHERE id = 1');
    }

    public function testBadCsrfIsRejectedWith400(): void
    {
        $this->seedKey();
        $data = $this->post('not-a-real-token');

        $this->assertSame(400, $this->client->getResponse()->getStatusCode());
        $this->assertFalse($data['ok']);
        $this->assertSame('csrf', $data['reason']);
        $this->assertSame([], $this->queued());
    }

    public function testMissingCsrfIsRejectedWith400(): void
    {
        $this->seedKey();
        $this->client->request('POST', self::SYNC, [], [], ['HTTP_ACCEPT' => 'application/json']);

        $this->assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testNonAdminIsForbidden(): void
    {
        $this->seedKey();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user   = new User();
        $user->setEmail('plain@test.local');
        $user->setDisplayName('Plain User');
        $user->setRoles(['ROLE_USER']);
        $user->setPassword($hasher->hashPassword($user, 'pw-pw-pw-pw'));
        $this->em()->persist($user);
        $this->em()->flush();
        $this->client->loginUser($user);

        $this->client->request('POST', self::SYNC, ['_token' => 'whatever']);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', self::STATE);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testEnabledWithKeyQueuesExactlyOneManualRun(): void
    {
        $this->seedKey();
        $token = $this->csrf();

        $data = $this->post($token);

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertTrue($data['ok']);
        $this->assertTrue($data['queued']);
        $this->assertTrue($data['state']['running'], 'the lock is taken synchronously so the UI can poll immediately');
        $this->assertSame('running', $data['state']['lastStatus']);

        $messages = $this->queued();
        $this->assertCount(1, $messages);
        $this->assertInstanceOf(SyncWokeometerCatalog::class, $messages[0]);
        $this->assertSame('manual', $messages[0]->trigger);
        $this->assertNotNull($messages[0]->runId);
        $this->assertFalse($messages[0]->forceFull, 'plain Sync now is not a forced full resync');
        $this->assertSame(0, $this->totalRequests(), 'queueing never touches the paid API');
    }

    public function testFullResyncForwardsTheForceFlag(): void
    {
        $this->seedKey();
        $data = $this->post($this->csrf(), ['full' => '1']);

        $this->assertTrue($data['queued']);
        $messages = $this->queued();
        $this->assertCount(1, $messages);
        $this->assertTrue($messages[0]->forceFull);
        $this->assertSame('full', $data['state']['runMode']);
    }

    public function testASecondImmediateCallIsRefusedAsLockedAndQueuesNothing(): void
    {
        $this->seedKey();
        $token = $this->csrf();

        $first = $this->post($token);
        $this->assertTrue($first['queued']);

        $second = $this->post($token);

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertTrue($second['ok']);
        $this->assertFalse($second['queued']);
        $this->assertSame('locked', $second['reason']);
        $this->assertTrue($second['state']['running']);
        $this->assertSame([], $this->queued(), 'a refused start dispatches nothing');
        $this->assertSame(0, $this->totalRequests());
    }

    public function testDisabledIsRefusedWithReasonDisabled(): void
    {
        $this->seedKey();
        $this->em()->persist(new Setting('wokeometer_enabled', '0'));
        $this->em()->flush();

        $data = $this->post($this->csrf());

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertFalse($data['queued']);
        $this->assertSame('disabled', $data['reason']);
        $this->assertSame([], $this->queued());
        $this->assertSame(0, $this->totalRequests());
    }

    public function testNoKeyIsRefusedWithReasonDisabled(): void
    {
        $data = $this->post($this->csrf());

        $this->assertFalse($data['queued']);
        $this->assertSame('disabled', $data['reason']);
        $this->assertFalse($data['state']['configured']);
    }

    public function testStateEndpointReturnsTheSpecShapeAndNeverTheKey(): void
    {
        $this->seedKey();
        $this->client->request('GET', self::STATE, [], [], ['HTTP_ACCEPT' => 'application/json']);
        $response = $this->client->getResponse();

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertTrue($data['ok']);

        $expected = ['enabled', 'configured', 'autoSync', 'running', 'runMode', 'runPhase', 'runRequests', 'runRecords',
            'lastStatus', 'lastErrorMessage', 'lastSuccessAt', 'lastRunStartedAt', 'lastRunFinishedAt', 'lastRunRequests',
            'lastRunRecords', 'totalRequests', 'creditsRemaining', 'creditsRemainingAt', 'nextAttemptAfter',
            'fullSyncCompletedAt', 'watermark', 'nextDueAt', 'cached', 'matchedTitles'];
        foreach ($expected as $key) {
            $this->assertArrayHasKey($key, $data['state'], $key);
        }
        $this->assertSame(['total', 'movies', 'series', 'seasons', 'withTmdb'], array_keys($data['state']['cached']));
        $this->assertTrue($data['state']['enabled']);
        $this->assertTrue($data['state']['configured']);
        $this->assertFalse($data['state']['running']);
        $this->assertNull($data['state']['matchedTitles'], 'cold library cache => unknown, never a fetch');
        $this->assertStringNotContainsString('wok_', (string) $response->getContent());
    }

    public function testMatchedTitlesAreCountedFromTheCachedLibraryOnlyAndOnlyOnRequest(): void
    {
        $this->seedKey();
        $em = $this->em();

        $movie = new WokeometerTitle('u-movie-603', 'movie', 'The Matrix', 1_700_000_000, 1_700_000_100, 1_700_000_200);
        $movie->setTmdbId(603)->setExternalSource('tmdb')->setExternalId('603')->setWokeScore(3)->setSlug('the-matrix')->setAnalyzed(true);
        $em->persist($movie);

        // A top-level tv title (parent NULL) matches...
        $show = new WokeometerTitle('u-tv-1399', 'tv', 'Game of Thrones', 1_700_000_000, 1_700_000_100, 1_700_000_200);
        $show->setTmdbId(1399)->setExternalSource('tmdb')->setExternalId('1399')->setWokeScore(2)->setSlug('got')->setAnalyzed(true);
        $em->persist($show);

        // ...a season row (parent set) must NOT make its library series match.
        $season = new WokeometerTitle('u-tv-1400-s1', 'tv', 'Some Show S1', 1_700_000_000, 1_700_000_100, 1_700_000_200);
        $season->setTmdbId(1400)->setExternalSource('tmdb')->setExternalId('1400')->setParentWokeometerId('u-tv-1400')->setSeasonNumber(1);
        $em->persist($season);
        $em->flush();

        // Warm the SHARED library cache the way MediaLibraryRefresher does.
        $swr = static::getContainer()->get(StaleWhileRevalidateCache::class);
        $swr->write('media.movies.radarr-1', [['tmdbId' => 603], ['tmdbId' => 604], ['tmdbId' => null]], MediaLibraryCache::HARD_TTL);
        $swr->write('media.series.sonarr-1', [['tmdbId' => 1399], ['tmdbId' => 1400]], MediaLibraryCache::HARD_TTL);

        // The 5 s poll variant never computes it.
        $this->client->request('GET', self::STATE, [], [], ['HTTP_ACCEPT' => 'application/json']);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertNull($data['state']['matchedTitles'], 'no ?stats=1 => not computed');

        // ?stats=1: 1 movie (603) + 1 tv (1399); the season-only 1400 and unknown 604 do not count.
        $this->client->request('GET', self::STATE . '?stats=1', [], [], ['HTTP_ACCEPT' => 'application/json']);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame(2, $data['state']['matchedTitles']);
        $this->assertSame(0, $this->totalRequests());

        // The page render keeps computing it.
        $crawler = $this->client->request('GET', '/admin/settings');
        $this->assertSame('2', trim($crawler->filter('[data-wokeometer-stat="matched_titles"]')->text()));
    }

    public function testDispatchFailureReleasesTheLockAndMarksError(): void
    {
        $this->seedKey();
        $token = $this->csrf();

        // Wrapped in TraceableMessageBus: the debug data collector type-checks the bus.
        $failing = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw new \RuntimeException('transport down: secret-detail');
            }
        };
        $this->overrideServices(['messenger.default_bus' => new TraceableMessageBus($failing)]);

        $data = $this->post($token);

        $this->assertFalse($data['ok']);
        $this->assertFalse($data['queued']);
        $this->assertSame('error', $data['reason']);
        $this->assertStringNotContainsString('secret-detail', (string) $this->client->getResponse()->getContent());

        $row = $this->em()->getConnection()->fetchAssociative('SELECT lock_run_id, last_status FROM wokeometer_sync_state WHERE id = 1');
        $this->assertNull($row['lock_run_id'], 'the lock taken by start() must be released when nothing was queued');
        $this->assertSame('error', $row['last_status']);
    }

    public function testStatusFailureAfterASuccessfulDispatchStillReportsQueued(): void
    {
        $this->seedKey();
        $token = $this->csrf();

        $sync = $this->createMock(WokeometerSyncService::class);
        $sync->method('start')->willReturn('run-abc');
        $sync->method('statusSummary')->willThrowException(new \RuntimeException('db hiccup'));
        $this->overrideServices([WokeometerSyncService::class => $sync]);

        $data = $this->post($token);

        $this->assertTrue($data['ok']);
        $this->assertTrue($data['queued'], 'the message was dispatched, so the run is committed');
        $this->assertNull($data['state']);
        $messages = $this->queued();
        $this->assertCount(1, $messages);
        $this->assertSame('run-abc', $messages[0]->runId);
    }

    public function testStateEndpointReportsAnErrorWhenTheStatusReadFails(): void
    {
        $sync = $this->createMock(WokeometerSyncService::class);
        $sync->method('statusSummary')->willThrowException(new \RuntimeException('db hiccup'));
        $this->overrideServices([WokeometerSyncService::class => $sync]);

        $this->client->request('GET', self::STATE, [], [], ['HTTP_ACCEPT' => 'application/json']);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->assertFalse($data['ok']);
        $this->assertSame('error', $data['reason']);
    }

    public function testEndpointsNeverLeakExceptionMessages(): void
    {
        $this->seedKey();
        // A broken table behind the state read must degrade to a generic error, never getMessage().
        $this->em()->getConnection()->executeStatement('DROP TABLE wokeometer_sync_state');

        $this->client->request('GET', self::STATE, [], [], ['HTTP_ACCEPT' => 'application/json']);
        $response = $this->client->getResponse();
        $body     = (string) $response->getContent();

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($body, true);
        $this->assertFalse($data['ok']);
        $this->assertSame('error', $data['reason']);
        $this->assertStringNotContainsString('wokeometer_sync_state', $body);
        $this->assertStringNotContainsString('SQLSTATE', $body);
    }
}
