<?php

namespace App\Tests\Controller;

use App\Entity\Media\WokeometerTitle;
use App\Entity\Setting;
use App\Entity\User;
use App\Message\SyncWokeometerCatalog;
use App\Service\Cache\StaleWhileRevalidateCache;
use App\Service\Media\MediaLibraryCache;
use App\Repository\Media\WokeometerSyncStateRepository;
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
        $this->assertSame(
            ['lastSuccessAt' => '—', 'nextDueAt' => '—', 'lastRunFinishedAt' => '—', 'lastRunStartedAt' => '—'],
            $data['state']['labels'],
            'never synced: every label is the dash',
        );
    }

    public function testStateLabelsAreFormattedServerSideLikePrismarrDatetime(): void
    {
        $this->seedKey();
        $db = $this->em()->getConnection();
        $db->executeStatement('INSERT OR IGNORE INTO wokeometer_sync_state (id) VALUES (1)');
        $db->executeStatement('UPDATE wokeometer_sync_state SET last_success_at = 1700000000, last_run_started_at = 1699990000, last_run_finished_at = 1700000000');

        $this->client->request('GET', self::STATE, [], [], ['HTTP_ACCEPT' => 'application/json']);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        $prefs    = static::getContainer()->get(\App\Service\DisplayPreferencesService::class);
        $expected = static fn (int $epoch): ?string => $prefs->formatDateTime(new \DateTimeImmutable('@' . $epoch));
        $labels   = $data['state']['labels'];
        $this->assertSame($expected(1_700_000_000), $labels['lastSuccessAt']);
        $this->assertSame($expected(1_700_000_000 + 30 * 86_400), $labels['nextDueAt']);
        $this->assertSame($expected(1_699_990_000), $labels['lastRunStartedAt']);
        $this->assertSame($expected(1_700_000_000), $labels['lastRunFinishedAt']);
        $this->assertStringContainsString('2023', (string) $labels['lastSuccessAt']);
        $this->assertSame(1_700_000_000, $data['state']['lastSuccessAt'], 'the raw epochs stay in the payload');
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

        // The page render no longer computes it: "…" until the card's one ?stats=1 fetch.
        $crawler = $this->client->request('GET', '/admin/settings');
        $this->assertSame('…', trim($crawler->filter('[data-wokeometer-stat="matched_titles"]')->text()));
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

        // A fresh run that never got a worker message must not linger as an
        // "interrupted run" the hourly tick would auto-start (the initial full
        // sync is manual-only).
        $orphan = $this->em()->getConnection()->fetchAssociative('SELECT run_phase, run_cursor, run_idempotency_key FROM wokeometer_sync_state WHERE id = 1');
        $this->assertNull($orphan['run_phase'], 'no orphan run phase');
        $this->assertNull($orphan['run_cursor']);
        $this->assertNull($orphan['run_idempotency_key']);
        $this->assertFalse(
            static::getContainer()->get(WokeometerSyncService::class)->isDue(time()),
            'the hourly tick must not auto-start the abandoned initial run'
        );
    }

    public function testDispatchFailureOnAResumedRunKeepsItsPosition(): void
    {
        $this->seedKey();
        $token = $this->csrf(); // renders the card → the state row exists

        $this->em()->getConnection()->executeStatement(
            "UPDATE wokeometer_sync_state SET run_phase = 'tv', run_cursor = 'c1', run_requests = 5, run_mode = 'full', lock_run_id = NULL WHERE id = 1"
        );

        $failing = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw new \RuntimeException('transport down');
            }
        };
        $this->overrideServices(['messenger.default_bus' => new TraceableMessageBus($failing)]);

        $data = $this->post($token);

        $this->assertFalse($data['queued']);
        $this->assertSame('error', $data['reason']);

        $row = $this->em()->getConnection()->fetchAssociative('SELECT lock_run_id, last_status, run_phase, run_cursor, run_requests FROM wokeometer_sync_state WHERE id = 1');
        $this->assertNull($row['lock_run_id'], 'the lock is released');
        $this->assertSame('error', $row['last_status']);
        $this->assertSame('tv', $row['run_phase'], 'the interrupted run keeps its phase so a later start can resume it');
        $this->assertSame('c1', $row['run_cursor']);
        $this->assertSame(5, (int) $row['run_requests']);
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

    public function testDispatchFailureNeverClobbersARunStartedRightAfterTheLockIsReleased(): void
    {
        // The failure path released the lock, THEN wrote state without an
        // owner check: a start landing in between (another tab, the worker's
        // scheduled start) had its fresh run_phase nulled → halted, auto
        // sync paused. The write must be owner-checked and happen first.
        $this->seedKey();
        $token = $this->csrf();

        $failing = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw new \RuntimeException('transport down');
            }
        };
        $registry = static::getContainer()->get('doctrine');
        $racing   = new class($registry) extends WokeometerSyncStateRepository {
            public function releaseLock(string $runId): bool
            {
                $released = parent::releaseLock($runId);
                // A competing start wins the lock the instant it is free.
                $this->acquireLock('competitor', time(), time() - 1800, 'full', 'schedule');

                return $released;
            }
        };
        $this->overrideServices([
            'messenger.default_bus'              => new TraceableMessageBus($failing),
            WokeometerSyncStateRepository::class => $racing,
        ]);

        $this->post($token);

        $row = $this->em()->getConnection()->fetchAssociative('SELECT lock_run_id, run_phase FROM wokeometer_sync_state WHERE id = 1');
        $this->assertSame('competitor', $row['lock_run_id']);
        $this->assertSame('movie', $row['run_phase'], 'the competing fresh run is left intact');
    }

    public function testDispatchFailureKeepsAPausedSyncPaused(): void
    {
        // start() overwrites last_status with "running"; the failure path then
        // wrote "error" — un-pausing a halted / request_cap sync, so the
        // scheduler could start a fresh run on its own (up to the 600 cap).
        $this->seedKey();
        $token = $this->csrf();
        $this->em()->getConnection()->executeStatement(
            "UPDATE wokeometer_sync_state SET last_status = 'halted', next_attempt_after = 123, run_phase = NULL, lock_run_id = NULL WHERE id = 1"
        );

        $failing = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw new \RuntimeException('transport down');
            }
        };
        $this->overrideServices(['messenger.default_bus' => new TraceableMessageBus($failing)]);

        $this->post($token);

        $row = $this->em()->getConnection()->fetchAssociative('SELECT last_status, next_attempt_after, lock_run_id FROM wokeometer_sync_state WHERE id = 1');
        $this->assertSame('halted', $row['last_status'], 'the pause survives a failed queue');
        $this->assertSame(123, (int) $row['next_attempt_after']);
        $this->assertNull($row['lock_run_id']);
    }

    public function testDispatchFailureReleasesTheLockEvenWhenTheStateWriteThrows(): void
    {
        $this->seedKey();
        $token = $this->csrf();

        $registry = static::getContainer()->get('doctrine');
        $flaky    = new class($registry) extends WokeometerSyncStateRepository {
            public bool $armed = false;

            public function update(array $fields, ?string $ownerRunId = null): int
            {
                if ($this->armed) {
                    throw new \RuntimeException('database is locked');
                }

                return parent::update($fields, $ownerRunId);
            }
        };
        $failing = new class($flaky) implements MessageBusInterface {
            public function __construct(private readonly object $repo) {}

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->repo->armed = true; // the next state write fails
                throw new \RuntimeException('transport down');
            }
        };
        $this->overrideServices([
            'messenger.default_bus'              => new TraceableMessageBus($failing),
            WokeometerSyncStateRepository::class => $flaky,
        ]);

        $this->post($token);

        $this->assertNull(
            $this->em()->getConnection()->fetchOne('SELECT lock_run_id FROM wokeometer_sync_state WHERE id = 1'),
            'the lock must not be held for 30 minutes because the status write failed'
        );
    }
}
