<?php

namespace App\Tests\Service\Wokeometer;

use App\Entity\Media\WokeometerSyncState;
use App\Entity\Media\WokeometerTitle;
use App\Repository\Media\WokeometerSyncStateRepository;
use App\Repository\Media\WokeometerTitleRepository;
use App\Service\Media\WokeometerClient;
use App\Service\Wokeometer\WokeometerChunkResult;
use App\Service\Wokeometer\WokeometerPageResult;
use App\Service\Wokeometer\WokeometerSettings;
use App\Service\Wokeometer\WokeometerSyncService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Sync orchestration against the REAL repositories on the test SQLite
 * (SchemaTool reset per test), a scripted fake client (no network I/O) and
 * a clock/pause-overridden service.
 */
#[AllowMockObjectsWithoutExpectations]
class WokeometerSyncServiceTest extends KernelTestCase
{
    private const T0 = 1_750_000_000;

    private WokeometerTitleRepository $titles;
    private WokeometerSyncStateRepository $state;
    private Connection $db;
    private ScriptedWokeometerClient $client;
    private TestableWokeometerSyncService $sync;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get('doctrine')->getManager();
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $titles = $em->getRepository(WokeometerTitle::class);
        $state  = $em->getRepository(WokeometerSyncState::class);
        $this->assertInstanceOf(WokeometerTitleRepository::class, $titles);
        $this->assertInstanceOf(WokeometerSyncStateRepository::class, $state);
        $this->titles = $titles;
        $this->state  = $state;
        $this->db     = $em->getConnection();

        $this->sync = $this->service();
    }

    private function settings(bool $enabled = true, bool $autoSync = true): WokeometerSettings
    {
        $s = $this->createStub(WokeometerSettings::class);
        $s->method('isEnabled')->willReturn($enabled);
        $s->method('isAutoSyncEnabled')->willReturn($autoSync);
        $s->method('apiKey')->willReturn('wok_test');
        return $s;
    }

    private function service(?WokeometerSettings $settings = null, ?LoggerInterface $logger = null): TestableWokeometerSyncService
    {
        $settings ??= $this->settings();
        $this->client = new ScriptedWokeometerClient($settings, new NullLogger(), $this->state);
        $svc = new TestableWokeometerSyncService($settings, $this->client, $this->titles, $this->state, $logger ?? new NullLogger());
        $svc->clock = self::T0;
        return $svc;
    }

    /** @param list<array{level: mixed, message: string, context: array<string, mixed>}> $records */
    private function recordingLogger(array &$records): LoggerInterface
    {
        return new class($records) extends \Psr\Log\AbstractLogger {
            /** @param list<array{level: mixed, message: string, context: array<string, mixed>}> $records */
            public function __construct(private array &$records) {}

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }

    /** @return array<string, mixed> */
    private function row(string $wid, string $type = 'movie', array $overrides = []): array
    {
        return array_merge([
            'wokeometerId'       => $wid,
            'mediaType'          => $type,
            'title'              => 'Title ' . $wid,
            'releaseDate'        => '2024-05-01',
            'wokeScore'          => 4,
            'tldr'               => 'Short summary.',
            'slug'               => 'title-' . $wid,
            'isAnalyzed'         => true,
            'lastUpdated'        => 1_700_000_000,
            'parentWokeometerId' => null,
            'seasonNumber'       => null,
            'externalSource'     => 'tmdb',
            'externalId'         => '100',
            'tmdbId'             => 100,
        ], $overrides);
    }

    /** @param list<string> $wids */
    private function page(array $wids, ?string $next, string $type = 'movie', ?int $credits = 90): WokeometerPageResult
    {
        return new WokeometerPageResult(
            WokeometerPageResult::OK,
            200,
            array_map(fn(string $w) => $this->row($w, $type), $wids),
            $next,
            $credits,
            1,
        );
    }

    private function failure(string $outcome, int $code, ?int $retryAfter = null): WokeometerPageResult
    {
        return new WokeometerPageResult($outcome, $code, retryAfter: $retryAfter, message: 'provider said ' . $outcome);
    }

    /** @return array<string, int|string|null> */
    private function st(): array
    {
        return $this->state->get();
    }

    private function mediaCount(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM wokeometer_media');
    }

    /** Script: 5 movie pages (m1..m10) + 4 tv pages (t1..t8) = 9 requests. */
    private function scriptFullCatalog(): void
    {
        $this->client->script = [
            $this->page(['m1', 'm2'], 'c1'),
            $this->page(['m3', 'm4'], 'c2'),
            $this->page(['m5', 'm6'], 'c3'),
            $this->page(['m7', 'm8'], 'c4'),
            $this->page(['m9', 'm10'], null),
            $this->page(['t1', 't2'], 'd1', 'tv'),
            $this->page(['t3', 't4'], 'd2', 'tv'),
            $this->page(['t5', 't6'], 'd3', 'tv'),
            $this->page(['t7', 't8'], null, 'tv', 77),
        ];
    }

    private function startRun(string $trigger = 'manual', bool $forceFull = false): string
    {
        $runId = $this->sync->start($trigger, $forceFull);
        $this->assertNotNull($runId, 'start() refused: ' . $this->sync->lastStartReason());
        return $runId;
    }

    // ── full sync ──────────────────────────────────────────────────────

    public function testFullSyncRunsBothPhasesAcrossChunksAndCommitsWatermarkOnlyAtTheEnd(): void
    {
        $this->scriptFullCatalog();
        $runId = $this->startRun();

        $s = $this->st();
        $this->assertSame('full', $s['run_mode']);
        $this->assertSame('running', $s['last_status']);

        $this->sync->clock = self::T0 + 10;
        $r1 = $this->sync->runChunk($runId);
        $this->assertSame(WokeometerChunkResult::CONTINUE, $r1->status);
        $this->assertSame(WokeometerSyncService::CHUNK_DELAY_SECONDS, $r1->delaySeconds);
        $this->assertCount(8, $this->client->calls, 'MAX_PAGES_PER_CHUNK pages per chunk');
        $this->assertSame(7, $this->sync->pauses, 'paced between pages, not after the last');
        $this->assertSame(WokeometerSyncService::PAGE_PACING_MICROS, $this->sync->pausedMicros[0]);

        $s = $this->st();
        $this->assertNull($s['watermark'], 'watermark is committed only when both phases finish');
        $this->assertNull($s['full_sync_completed_at']);
        $this->assertSame('tv', $s['run_phase']);
        $this->assertSame('d3', $s['run_cursor']);
        $this->assertSame($runId, $s['lock_run_id'], 'lock kept across a continue');
        $this->assertSame(8, $s['run_requests']);
        $this->assertSame(16, $s['run_records']);

        $this->sync->clock = self::T0 + 500;
        $r2 = $this->sync->runChunk($runId);
        $this->assertSame(WokeometerChunkResult::DONE, $r2->status);
        $this->assertCount(9, $this->client->calls);
        $this->assertSame(7, $this->sync->pauses, 'no pause after the final page');

        // Request sequence: movie phase then tv phase, following cursors, no updated_since.
        $this->assertSame(
            [['movie', null], ['movie', 'c1'], ['movie', 'c2'], ['movie', 'c3'], ['movie', 'c4'],
             ['tv', null], ['tv', 'd1'], ['tv', 'd2'], ['tv', 'd3']],
            array_map(fn(array $c) => [$c['type'], $c['after']], $this->client->calls),
        );
        foreach ($this->client->calls as $c) {
            $this->assertNull($c['updatedSince']);
        }

        $s = $this->st();
        $this->assertSame(self::T0, $s['watermark'], 'watermark = run start, captured before the first request');
        $this->assertSame(self::T0 + 500, $s['full_sync_completed_at']);
        $this->assertSame(self::T0 + 500, $s['last_success_at']);
        $this->assertSame(self::T0 + 500, $s['last_run_finished_at']);
        $this->assertSame('ok', $s['last_status']);
        $this->assertSame('done', $s['run_phase']);
        $this->assertNull($s['lock_run_id'], 'lock released');
        $this->assertSame(9, $s['last_run_requests']);
        $this->assertSame(18, $s['last_run_records']);
        $this->assertSame(9, $s['total_requests']);
        $this->assertSame(77, $s['credits_remaining']);
        $this->assertSame(self::T0 + 500, $s['credits_remaining_at']);
        $this->assertNull($s['run_idempotency_key'], 'key rotated after the page committed');
        $this->assertSame(18, $this->mediaCount());
    }

    public function testFullSweepDeletesOnlyRowsTheRunNeverSaw(): void
    {
        $this->titles->upsertRows([$this->row('gone'), $this->row('m1')], self::T0 - 1000, self::T0 - 1000);
        $this->assertSame(2, $this->mediaCount());

        $this->client->script = [$this->page(['m1', 'm2'], null), $this->page(['t1'], null, 'tv')];
        $runId = $this->startRun();
        $this->assertSame(WokeometerChunkResult::DONE, $this->sync->runChunk($runId)->status);

        $wids = $this->db->fetchFirstColumn('SELECT wokeometer_id FROM wokeometer_media ORDER BY wokeometer_id');
        $this->assertSame(['m1', 'm2', 't1'], $wids);
    }

    public function testIncrementalUsesWatermarkMinusOverlapAndNeverSweeps(): void
    {
        $watermark = self::T0 - 40 * 86_400;
        $this->state->update(['full_sync_completed_at' => $watermark, 'watermark' => $watermark, 'last_success_at' => $watermark]);
        $this->titles->upsertRows([$this->row('old')], $watermark - 5000, $watermark - 5000);

        $this->client->script = [$this->page(['m1'], null), $this->page([], null, 'tv')];
        $runId = $this->startRun('schedule');
        $this->assertSame('incremental', $this->st()['run_mode']);
        $this->assertSame(WokeometerChunkResult::DONE, $this->sync->runChunk($runId)->status);

        $this->assertCount(2, $this->client->calls);
        foreach ($this->client->calls as $c) {
            $this->assertSame($watermark - WokeometerSyncService::OVERLAP_SECONDS, $c['updatedSince']);
            $this->assertSame($watermark - 172_800, $c['updatedSince']);
        }
        $s = $this->st();
        $this->assertSame(self::T0, $s['watermark']);
        $this->assertSame($watermark, $s['full_sync_completed_at'], 'an incremental run does not touch full_sync_completed_at');
        $this->assertSame(2, $this->mediaCount(), 'incremental runs never sweep');
    }

    public function testEmptyPageWithACursorFollowsTheCursor(): void
    {
        // Only next_cursor === null ends a phase: an empty page may be a
        // filtered-out stretch of the catalog, not its end.
        $this->client->script = [
            $this->page([], 'c9'), $this->page(['m1'], null),
            $this->page([], 'd9', 'tv'), $this->page(['t1'], null, 'tv'),
        ];
        $runId = $this->startRun();
        $this->assertSame(WokeometerChunkResult::DONE, $this->sync->runChunk($runId)->status);
        $this->assertSame(
            [['movie', null], ['movie', 'c9'], ['tv', null], ['tv', 'd9']],
            array_map(fn(array $c) => [$c['type'], $c['after']], $this->client->calls),
        );
        $this->assertSame(2, $this->mediaCount());
    }

    public function testEmptyPageRepeatingItsCursorTripsTheLoopGuard(): void
    {
        $this->client->script = [$this->page([], 'c9'), $this->page([], 'c9'), $this->page([], null)];
        $r = $this->sync->runChunk($this->startRun());

        $this->assertSame('halted', $r->reason);
        $this->assertCount(2, $this->client->calls, 'no third paid request');
    }

    public function testRerunningTheSamePagesIsIdempotent(): void
    {
        $this->client->script = [$this->page(['m1', 'm2'], null), $this->page(['t1'], null, 'tv')];
        $this->sync->runChunk($this->startRun());
        $this->assertSame(3, $this->mediaCount());

        $this->sync->clock = self::T0 + 100;
        $this->client->script = [$this->page(['m1', 'm2'], null), $this->page(['t1'], null, 'tv')];
        $this->assertSame(WokeometerChunkResult::DONE, $this->sync->runChunk($this->startRun('manual', true))->status);
        $this->assertSame(3, $this->mediaCount());
        $this->assertSame(4, $this->st()['total_requests']);
    }

    public function testReplayedPageIsCountedForTheRunButNotAsBilled(): void
    {
        $this->client->script = [
            new WokeometerPageResult(WokeometerPageResult::OK, 200, [$this->row('m1')], null, 50, 0, true),
            $this->page([], null, 'tv'),
        ];
        $this->sync->runChunk($this->startRun());
        $s = $this->st();
        $this->assertSame(2, $s['last_run_requests']);
        $this->assertSame(1, $s['total_requests'], 'an Idempotency-Replayed page was free');
    }

    // ── idempotency key ───────────────────────────────────────────────

    public function testIdempotencyKeyIsPersistedBeforeEveryRequestAndRotatedAfter(): void
    {
        $this->client->script = [$this->page(['m1'], 'c1'), $this->page(['m2'], null), $this->page([], null, 'tv')];
        $this->sync->runChunk($this->startRun());

        $this->assertCount(3, $this->client->calls);
        foreach ($this->client->calls as $c) {
            $this->assertTrue($c['keyPersisted'], 'the key must be in the state row before the client is called');
            $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $c['key']);
        }
        $keys = array_column($this->client->calls, 'key');
        $this->assertCount(3, array_unique($keys), 'a fresh key per committed page');
    }

    // ── failures ──────────────────────────────────────────────────────

    public function testOutOfCreditsStopsKeepsCursorAndBacksOff(): void
    {
        $this->client->script = [$this->page(['m1'], 'c1'), $this->failure(WokeometerPageResult::OUT_OF_CREDITS, 402)];
        $runId = $this->startRun();
        $r = $this->sync->runChunk($runId);

        $this->assertSame(WokeometerChunkResult::STOPPED, $r->status);
        $this->assertSame('out_of_credits', $r->reason);
        $s = $this->st();
        $this->assertNull($s['watermark'], 'a failed page never advances the watermark');
        $this->assertSame('movie', $s['run_phase']);
        $this->assertSame('c1', $s['run_cursor'], 'cursor kept for resume');
        $this->assertNull($s['run_idempotency_key'], 'a 402 was never executed: a fresh key on resume cannot double-bill, and avoids a replayed 402');
        $this->assertSame(self::T0 + WokeometerSyncService::CREDITS_BACKOFF_SECONDS, $s['next_attempt_after']);
        $this->assertSame('out_of_credits', $s['last_status']);
        $this->assertSame(402, $s['last_error_code']);
        $this->assertSame('provider said out_of_credits', $s['last_error_message']);
        $this->assertNull($s['lock_run_id']);
        $this->assertSame(self::T0, $s['last_run_finished_at']);
        $this->assertSame(1, $s['last_run_requests'], 'the unbilled 402 is not a run request');
        $this->assertSame(1, $s['last_run_records']);

        // The scheduled path still respects the backoff…
        $this->assertFalse($this->sync->isDue(self::T0 + 3600), 'backoff blocks the tick');
        $this->assertNull($this->sync->start('schedule', false, self::T0 + 3600));
        $this->assertSame('backoff', $this->sync->lastStartReason());

        // …but a manual start bypasses it and resumes from the cursor.
        $manual = $this->sync->start('manual', false, self::T0 + 3600);
        $this->assertNotNull($manual);
        $this->assertNull($this->sync->lastStartReason());
        $s = $this->st();
        $this->assertSame($manual, $s['lock_run_id']);
        $this->assertSame('c1', $s['run_cursor'], 'manual start resumed, not restarted');
        $this->assertSame(self::T0, $s['run_started_at']);
        $this->assertNull($s['next_attempt_after'], 'a successful (manual) start clears the backoff');
        $this->assertFalse($this->sync->isDue(self::T0 + 3600), 'isDue() false: a live run holds the lock');
    }

    public function testResumeAfterOutOfCreditsContinuesFromTheCursorWithANewRunId(): void
    {
        $this->client->script = [$this->page(['m1'], 'c1'), $this->failure(WokeometerPageResult::OUT_OF_CREDITS, 402)];
        $first = $this->startRun();
        $this->sync->runChunk($first);

        $later = self::T0 + WokeometerSyncService::CREDITS_BACKOFF_SECONDS + 1;
        $this->assertTrue($this->sync->isDue($later), 'an interrupted run is due again once the backoff expires');

        $this->sync->clock = $later;
        $this->client->calls = [];
        $this->client->script = [$this->page(['m2'], null), $this->page(['t1'], null, 'tv')];
        $second = $this->startRun('schedule');
        $this->assertNotSame($first, $second);
        $this->assertSame('running', $this->st()['last_status']);

        $this->assertSame(WokeometerChunkResult::DONE, $this->sync->runChunk($second)->status);
        $this->assertSame([['movie', 'c1'], ['tv', null]], array_map(fn(array $c) => [$c['type'], $c['after']], $this->client->calls));
        $s = $this->st();
        $this->assertSame(self::T0, $s['watermark'], 'a resumed run keeps its ORIGINAL start as watermark');
        $this->assertSame('ok', $s['last_status']);
        $this->assertNull($s['last_error_code']);
        $this->assertSame(3, $s['last_run_requests'], 'run counters carried across the resume');
        $this->assertSame(3, $this->mediaCount());
    }

    public function testRateLimitedContinuesAfterRetryAfterWithTheSameKey(): void
    {
        $this->client->script = [
            $this->failure(WokeometerPageResult::RATE_LIMITED, 429, 17),
            $this->page(['m1'], null),
            $this->page([], null, 'tv'),
        ];
        $runId = $this->startRun();
        $r = $this->sync->runChunk($runId);

        $this->assertSame(WokeometerChunkResult::CONTINUE, $r->status);
        $this->assertSame(17, $r->delaySeconds);
        $s = $this->st();
        $this->assertSame($runId, $s['lock_run_id']);
        $this->assertSame($this->client->calls[0]['key'], $s['run_idempotency_key']);

        $this->assertSame(WokeometerChunkResult::DONE, $this->sync->runChunk($runId)->status);
        $this->assertSame($this->client->calls[0]['key'], $this->client->calls[1]['key'], 'the throttled page is retried with the same Idempotency-Key');
        $this->assertNotSame($this->client->calls[1]['key'], $this->client->calls[2]['key']);
    }

    public function testRateLimitedDelayIsBoundedAndDefaulted(): void
    {
        $this->client->script = [$this->failure(WokeometerPageResult::RATE_LIMITED, 429, 3600)];
        $runId = $this->startRun();
        $delay = $this->sync->runChunk($runId)->delaySeconds;
        $this->assertSame(WokeometerSyncService::MAX_RATE_LIMIT_WAIT_SECONDS, $delay);
        $this->assertLessThan(WokeometerSyncService::STALE_LOCK_SECONDS, $delay, 'never waits past the stale-lock window');

        $this->client->script = [$this->failure(WokeometerPageResult::RATE_LIMITED, 429)];
        $this->assertSame(60, $this->sync->runChunk($runId)->delaySeconds);
    }

    public function testTransientFailuresRetryWithSameKeyThenStopResumably(): void
    {
        $this->client->script = [
            $this->page(['m1'], 'c1'),
            $this->failure(WokeometerPageResult::TRANSIENT, 503),
            $this->failure(WokeometerPageResult::TRANSPORT, 0),
            $this->failure(WokeometerPageResult::TRANSIENT, 503),
        ];
        $runId = $this->startRun();

        $r1 = $this->sync->runChunk($runId);
        $this->assertSame(WokeometerChunkResult::CONTINUE, $r1->status);
        $this->assertSame(WokeometerSyncService::TRANSIENT_RETRY_SECONDS, $r1->delaySeconds);
        $this->assertSame(1, $this->st()['run_transient_failures']);
        $this->assertSame(WokeometerChunkResult::CONTINUE, $this->sync->runChunk($runId)->status);

        $r3 = $this->sync->runChunk($runId);
        $this->assertSame(WokeometerChunkResult::STOPPED, $r3->status);
        $this->assertSame('error', $r3->reason);
        $retryKeys = array_column(array_slice($this->client->calls, 1), 'key');
        $this->assertCount(3, $retryKeys);
        $this->assertCount(1, array_unique($retryKeys), 'same key on every retry');

        $s = $this->st();
        $this->assertSame('error', $s['last_status']);
        $this->assertSame('movie', $s['run_phase'], 'resumable: unbilled failures must not force a re-billed restart');
        $this->assertSame('c1', $s['run_cursor']);
        $this->assertSame($retryKeys[0], $s['run_idempotency_key']);
        $this->assertSame(self::T0, $s['run_started_at']);
        $this->assertSame(0, $s['run_transient_failures'], 'fresh budget for the resumed run');
        $this->assertSame(self::T0 + WokeometerSyncService::TRANSIENT_BACKOFF_SECONDS, $s['next_attempt_after']);
        $this->assertSame(3_600, WokeometerSyncService::TRANSIENT_BACKOFF_SECONDS);
        $this->assertNull($s['lock_run_id']);
        $this->assertFalse($this->sync->isDue(self::T0 + 1800));
        $this->assertTrue($this->sync->isDue(self::T0 + 3600));

        // Resume after the backoff: same cursor, same (free-replay) key.
        $this->sync->clock = self::T0 + 3600;
        $this->client->calls = [];
        $this->client->script = [$this->page(['m2'], null), $this->page([], null, 'tv')];
        $this->assertSame(WokeometerChunkResult::DONE, $this->sync->runChunk($this->startRun('schedule'))->status);
        $this->assertSame('c1', $this->client->calls[0]['after']);
        $this->assertSame($retryKeys[0], $this->client->calls[0]['key']);
        $this->assertSame(self::T0, $this->st()['watermark']);
    }

    public function testTransientCounterResetsAfterASuccessfulPage(): void
    {
        $this->client->script = [
            $this->failure(WokeometerPageResult::TRANSIENT, 503),
            $this->failure(WokeometerPageResult::TRANSIENT, 503),
            $this->page(['m1'], 'c1'),
            $this->failure(WokeometerPageResult::TRANSIENT, 503),
            $this->failure(WokeometerPageResult::TRANSIENT, 503),
            $this->page(['m2'], null),
            $this->page([], null, 'tv'),
        ];
        $runId = $this->startRun();
        $statuses = [];
        for ($i = 0; $i < 5; $i++) {
            $statuses[] = $this->sync->runChunk($runId)->status;
        }
        // Without the reset, chunk 4 would be the 3rd failure → stopped.
        $this->assertSame(['continue', 'continue', 'continue', 'continue', 'done'], $statuses);
    }

    /** A first full sync completed 40 days ago: without a pause, a scheduled sync would be due. */
    private function overdueAfterAFullSync(): void
    {
        $done = self::T0 - 40 * 86_400;
        $this->state->update(['full_sync_completed_at' => $done, 'watermark' => $done, 'last_success_at' => $done]);
    }

    /** After a runaway stop the scheduler never starts again on its own; a manual start still does. */
    private function assertPausedUntilManualStart(): void
    {
        foreach ([self::T0 + 86_400, self::T0 + 31 * 86_400, self::T0 + 365 * 86_400] as $at) {
            $this->assertFalse($this->sync->isDue($at), 'paused at +' . ($at - self::T0) . ' s');
            $this->assertNull($this->sync->start('schedule', false, $at));
            $this->assertSame('not_due', $this->sync->lastStartReason());
        }

        $this->sync->clock = self::T0 + 60;
        $this->client->calls = [];
        $this->client->script = [$this->page(['m1'], null), $this->page([], null, 'tv')];
        $runId = $this->startRun('manual');
        $s = $this->st();
        $this->assertSame('running', $s['last_status'], 'a manual start clears the pause');
        $this->assertNull($s['next_attempt_after'], 'and the backoff');
        $this->assertSame(WokeometerChunkResult::DONE, $this->sync->runChunk($runId)->status);
        $this->assertSame([null, null], array_column($this->client->calls, 'after'), 'fresh: re-read from page 1');
        $this->assertTrue($this->sync->isDue(self::T0 + 60 + 31 * 86_400), 'scheduling is back after a good run');
    }

    public function testCursorThatDoesNotAdvanceHaltsAndPausesAutomaticSync(): void
    {
        $this->overdueAfterAFullSync();
        $this->client->script = [$this->page(['m1'], 'c1'), $this->page(['m2'], 'c1'), $this->page(['m3'], 'c2')];
        $runId = $this->startRun();
        $r = $this->sync->runChunk($runId);

        $this->assertSame(WokeometerChunkResult::STOPPED, $r->status);
        $this->assertSame(WokeometerSyncService::STATUS_HALTED, $r->reason);
        $this->assertSame('halted', $r->reason);
        $this->assertCount(2, $this->client->calls, 'no third paid request');
        $s = $this->st();
        $this->assertSame('halted', $s['last_status']);
        $this->assertSame('cursor did not advance', $s['last_error_message']);
        $this->assertNull($s['run_phase']);
        $this->assertSame(self::T0 + WokeometerSyncService::ERROR_BACKOFF_SECONDS, $s['next_attempt_after']);
        $this->assertSame(2, $s['total_requests'], 'both billed pages recorded');

        $this->assertPausedUntilManualStart();
    }

    public function testRequestCapStopsTheRunAndPausesAutomaticSync(): void
    {
        $this->overdueAfterAFullSync();
        $this->client->script = [$this->page(['m1'], 'c1'), $this->page(['m2'], 'c2')];
        $runId = $this->startRun();
        $this->state->update(['run_requests' => WokeometerSyncService::REQUEST_CAP_PER_RUN - 1]);

        $r = $this->sync->runChunk($runId);
        $this->assertSame(WokeometerChunkResult::STOPPED, $r->status);
        $this->assertSame('request_cap', $r->reason);
        $this->assertCount(1, $this->client->calls);
        $s = $this->st();
        $this->assertSame('request_cap', $s['last_status']);
        $this->assertNull($s['run_phase']);
        $this->assertSame(self::T0 + WokeometerSyncService::ERROR_BACKOFF_SECONDS, $s['next_attempt_after']);
        $this->assertSame(WokeometerSyncService::REQUEST_CAP_PER_RUN, $s['last_run_requests']);
        $this->assertNull($s['lock_run_id']);

        $this->assertPausedUntilManualStart();
    }

    public function testUnknownRunPhaseHaltsAndPausesAutomaticSync(): void
    {
        $this->overdueAfterAFullSync();
        $runId = $this->startRun();
        $this->state->update(['run_phase' => 'bogus']);

        $r = $this->sync->runChunk($runId);
        $this->assertSame('halted', $r->reason);
        $this->assertSame([], $this->client->calls);
        $this->assertSame('invalid run phase', $this->st()['last_error_message']);
        $this->assertNull($this->st()['run_phase']);

        $this->assertPausedUntilManualStart();
    }

    public function testRequestCapIsPreCheckedBeforeARequest(): void
    {
        $this->client->script = [$this->page(['m1'], 'c1')];
        $runId = $this->startRun();
        $this->state->update(['run_requests' => WokeometerSyncService::REQUEST_CAP_PER_RUN]);

        $r = $this->sync->runChunk($runId);
        $this->assertSame('request_cap', $r->reason);
        $this->assertSame([], $this->client->calls, 'no request once the cap is reached');
        $this->assertNull($this->st()['run_phase']);
    }

    /**
     * Resumable stop of the 2nd page: phase, cursor c1, key and run start kept,
     * transient budget reset, lock released, one "stopped" warning.
     *
     * @return array{0: array<string, int|string|null>, 1: string, 2: list<array{level: mixed, message: string, context: array<string, mixed>}>}
     */
    private function stopOnSecondPage(WokeometerPageResult $failure): array
    {
        $records = [];
        $this->sync = $this->service(logger: $this->recordingLogger($records));
        $this->client->script = [$this->page(['m1'], 'c1'), $failure];
        $this->sync->runChunk($this->startRun());
        $s = $this->st();

        $this->assertSame('movie', $s['run_phase'], $failure->outcome);
        $this->assertSame('c1', $s['run_cursor'], $failure->outcome);
        $this->assertSame($this->client->calls[1]['key'], $s['run_idempotency_key'], $failure->outcome . ': same key kept');
        $this->assertSame(self::T0, $s['run_started_at'], $failure->outcome);
        $this->assertSame(0, $s['run_transient_failures'], $failure->outcome);
        $this->assertNull($s['lock_run_id'], $failure->outcome);

        $stops = array_values(array_filter($records, fn(array $r) => $r['message'] === 'Wokeometer sync stopped'));
        $this->assertCount(1, $stops, $failure->outcome);
        $this->assertSame('warning', $stops[0]['level']);
        $this->assertSame(['status', 'code', 'message', 'resumable'], array_keys($stops[0]['context']));
        $this->assertTrue($stops[0]['context']['resumable']);
        $this->assertStringNotContainsString($this->client->calls[1]['key'], (string) json_encode($records), 'never log the key');

        return [$s, $this->client->calls[1]['key'], $records];
    }

    public function testAuthStopKeepsTheCursorForTheNextKey(): void
    {
        [$s] = $this->stopOnSecondPage($this->failure(WokeometerPageResult::AUTH, 401));
        $this->assertSame('auth', $s['last_status']);
        $this->assertSame(401, $s['last_error_code']);
        $this->assertSame(self::T0 + WokeometerSyncService::AUTH_BACKOFF_SECONDS, $s['next_attempt_after']);

        // A key save clears the backoff (Task 6) → the run continues where it stopped.
        $this->state->update(['next_attempt_after' => null]);
        $this->client->calls = [];
        $this->client->script = [$this->page(['m2'], null), $this->page([], null, 'tv')];
        $this->assertSame(WokeometerChunkResult::DONE, $this->sync->runChunk($this->startRun('schedule'))->status);
        $this->assertSame('c1', $this->client->calls[0]['after']);
    }

    public function testForbiddenStopIsResumable(): void
    {
        [$s] = $this->stopOnSecondPage($this->failure(WokeometerPageResult::FORBIDDEN, 403));
        $this->assertSame('forbidden', $s['last_status']);
        $this->assertSame(self::T0 + WokeometerSyncService::AUTH_BACKOFF_SECONDS, $s['next_attempt_after']);
    }

    public function testConflictStopIsResumableAndReplaysTheSameKey(): void
    {
        [$s, $key] = $this->stopOnSecondPage($this->failure(WokeometerPageResult::CONFLICT, 409));
        $this->assertSame('error', $s['last_status']);
        $this->assertSame(409, $s['last_error_code']);
        $this->assertSame(self::T0 + WokeometerSyncService::TRANSIENT_BACKOFF_SECONDS, $s['next_attempt_after']);

        $this->sync->clock = self::T0 + WokeometerSyncService::TRANSIENT_BACKOFF_SECONDS;
        $this->client->calls = [];
        $this->client->script = [$this->page(['m2'], null), $this->page([], null, 'tv')];
        $this->assertSame(WokeometerChunkResult::DONE, $this->sync->runChunk($this->startRun('schedule'))->status);
        $this->assertSame(['c1', $key], [$this->client->calls[0]['after'], $this->client->calls[0]['key']]);
    }

    public function testUnconfiguredMidRunIsResumable(): void
    {
        [$s] = $this->stopOnSecondPage($this->failure(WokeometerPageResult::UNCONFIGURED, 0));
        $this->assertSame('error', $s['last_status']);
        $this->assertSame(self::T0 + WokeometerSyncService::TRANSIENT_BACKOFF_SECONDS, $s['next_attempt_after']);
    }

    public function testInvalidStopIsResumableWithItsOwnStatus(): void
    {
        [$s] = $this->stopOnSecondPage($this->failure(WokeometerPageResult::INVALID, 404));
        $this->assertSame('invalid', $s['last_status']);
        $this->assertSame(WokeometerSyncService::STATUS_INVALID, $s['last_status']);
        $this->assertSame(self::T0 + WokeometerSyncService::INVALID_BACKOFF_SECONDS, $s['next_attempt_after']);
    }

    public function testA400OnACursorPageRestartsThatPhaseOnResume(): void
    {
        // tv phase, second page: the cursor is the likely culprit.
        $this->client->script = [
            $this->page(['m1'], null),
            $this->page(['t1'], 'd1', 'tv'),
            $this->failure(WokeometerPageResult::INVALID, 400),
        ];
        $this->sync->runChunk($this->startRun());
        $s = $this->st();

        $this->assertSame('invalid', $s['last_status']);
        $this->assertSame(self::T0 + WokeometerSyncService::INVALID_BACKOFF_SECONDS, $s['next_attempt_after'], 'still the 7-day backoff');
        $this->assertSame('tv', $s['run_phase'], 'still resumable: the phase is kept');
        $this->assertNull($s['run_cursor'], 'the rejected cursor is dropped');
        $this->assertNull($s['run_idempotency_key']);
        $this->assertSame(self::T0, $s['run_started_at']);

        $later = self::T0 + WokeometerSyncService::INVALID_BACKOFF_SECONDS;
        $this->sync->clock = $later;
        $this->assertFalse($this->sync->isDue($later), 'invalid pauses automatic sync');
        $this->client->calls = [];
        $this->client->script = [$this->page(['t1'], null, 'tv')];
        $this->assertSame(WokeometerChunkResult::DONE, $this->sync->runChunk($this->startRun('manual'))->status);
        $this->assertSame([['tv', null]], array_map(fn(array $c) => [$c['type'], $c['after']], $this->client->calls), 'tv restarts at its first page; movies are not re-read');
        $this->assertSame(self::T0, $this->st()['watermark']);
    }

    public function testA400OnTheFirstPageOfAPhaseKeepsTheKey(): void
    {
        $this->client->script = [$this->failure(WokeometerPageResult::INVALID, 400)];
        $this->sync->runChunk($this->startRun());
        $s = $this->st();

        $this->assertSame('movie', $s['run_phase']);
        $this->assertNull($s['run_cursor']);
        $this->assertSame($this->client->calls[0]['key'], $s['run_idempotency_key'], 'no cursor to blame: same key, free replay');
    }

    public function testUnreadableBilledPageStopsResumablyWithAWeekBackoffAndRecordsCredits(): void
    {
        $this->client->script = [
            $this->page(['m1'], 'c1', credits: 60),
            new WokeometerPageResult(WokeometerPageResult::INVALID, 200, [], null, 59, 1, message: 'no usable rows in page'),
        ];
        $r = $this->sync->runChunk($this->startRun());

        $this->assertSame('invalid', $r->reason);
        $s = $this->st();
        $this->assertSame('invalid', $s['last_status']);
        $this->assertSame('no usable rows in page', $s['last_error_message']);
        $this->assertSame('movie', $s['run_phase'], 'a retry re-reads just that page instead of restarting');
        $this->assertSame('c1', $s['run_cursor']);
        $this->assertSame(self::T0 + WokeometerSyncService::INVALID_BACKOFF_SECONDS, $s['next_attempt_after']);
        $this->assertSame(604_800, WokeometerSyncService::INVALID_BACKOFF_SECONDS);
        $this->assertSame(59, $s['credits_remaining'], 'the 2xx was billed: its credits header is recorded');
        $this->assertSame(2, $s['total_requests'], 'the billed invalid page counts as a lifetime request');
    }

    public function testDroppedRowsLogOneWarningPerChunk(): void
    {
        $records = [];
        $this->sync = $this->service(logger: $this->recordingLogger($records));
        $this->client->script = [
            new WokeometerPageResult(WokeometerPageResult::OK, 200, [$this->row('m1')], 'c1', 50, 1, droppedRows: 2),
            new WokeometerPageResult(WokeometerPageResult::OK, 200, [$this->row('m2')], null, 49, 1, droppedRows: 3),
            $this->page([], null, 'tv'),
        ];
        $this->assertSame(WokeometerChunkResult::DONE, $this->sync->runChunk($this->startRun())->status);

        $warnings = array_values(array_filter($records, fn(array $r) => $r['level'] === 'warning'));
        $this->assertCount(1, $warnings);
        $this->assertSame(['path', 'code', 'message'], array_keys($warnings[0]['context']));
        $this->assertStringContainsString('5', $warnings[0]['context']['message']);
    }

    public function testThrowableAfterTheRequestStopsResumablyWithSanitizedClassNameAndSameKey(): void
    {
        $records = [];
        $this->sync = $this->service(logger: $this->recordingLogger($records));
        $this->client->script = [$this->page(['m1'], 'c1'), new \RuntimeException('secret wok_deadbeef in message')];
        $runId = $this->startRun();
        $r = $this->sync->runChunk($runId);

        $this->assertSame(WokeometerChunkResult::STOPPED, $r->status);
        $this->assertSame('error', $r->reason);
        $key = $this->client->calls[1]['key'];
        $s = $this->st();
        $this->assertSame('error', $s['last_status']);
        $this->assertSame('RuntimeException', $s['last_error_message'], 'class name only, never getMessage()');
        $this->assertNull($s['lock_run_id'], 'lock released');
        $this->assertSame('movie', $s['run_phase']);
        $this->assertSame('c1', $s['run_cursor']);
        $this->assertSame($key, $s['run_idempotency_key'], 'key kept: the replay is free');
        $this->assertSame(self::T0 + WokeometerSyncService::INTERNAL_ERROR_BACKOFF_SECONDS, $s['next_attempt_after']);
        $this->assertSame(21_600, WokeometerSyncService::INTERNAL_ERROR_BACKOFF_SECONDS);
        $this->assertFalse($this->sync->isDue(self::T0 + WokeometerSyncService::TRANSIENT_BACKOFF_SECONDS), 'not retried after the 1 h transient backoff');
        $this->assertStringNotContainsString('wok_deadbeef', (string) json_encode($records));

        $this->sync->clock = self::T0 + WokeometerSyncService::INTERNAL_ERROR_BACKOFF_SECONDS;
        $this->client->calls = [];
        $this->client->script = [$this->page(['m2'], null), $this->page([], null, 'tv')];
        $this->assertSame(WokeometerChunkResult::DONE, $this->sync->runChunk($this->startRun('schedule'))->status);
        $this->assertSame(['c1', $key], [$this->client->calls[0]['after'], $this->client->calls[0]['key']]);
    }

    // ── start / lock ──────────────────────────────────────────────────

    public function testStartIsRefusedWhileAnotherLiveRunHoldsTheLock(): void
    {
        $this->state->acquireLock('other-run', self::T0, self::T0 - 1800, 'full', 'manual');
        $this->assertNull($this->sync->start('manual', false));
        $this->assertSame('locked', $this->sync->lastStartReason());
        $this->assertSame('other-run', $this->st()['lock_run_id']);
    }

    public function testStaleLockTakeoverKeepsTheCursorAndTheOldRunIsLockedOut(): void
    {
        $this->client->script = [$this->page(['m1'], 'c1'), $this->failure(WokeometerPageResult::RATE_LIMITED, 429, 5)];
        $old = $this->startRun();
        $this->sync->runChunk($old); // page 1 committed, then 429 → continue (lock still held)
        $key = $this->st()['run_idempotency_key'];

        // The consumer died: the continuation never ran and the heartbeat went stale.
        $this->sync->clock = self::T0 + WokeometerSyncService::STALE_LOCK_SECONDS + 60;
        $this->assertTrue($this->sync->isDue($this->sync->clock), 'a stale lock counts as due (takeover)');
        $new = $this->startRun('schedule');
        $this->assertNotSame($old, $new);

        $this->client->calls = [];
        $this->client->script = [];
        $r = $this->sync->runChunk($old);
        $this->assertSame(WokeometerChunkResult::STOPPED, $r->status, 'the superseded run does nothing');
        $this->assertSame([], $this->client->calls);

        $this->client->script = [$this->page(['m2'], null), $this->page([], null, 'tv')];
        $this->assertSame(WokeometerChunkResult::DONE, $this->sync->runChunk($new)->status);
        $this->assertSame('c1', $this->client->calls[0]['after']);
        $this->assertSame($key, $this->client->calls[0]['key'], 'the in-flight page replays with its persisted key');
        $this->assertSame(self::T0, $this->st()['watermark']);
    }

    public function testDisabledStartsNothing(): void
    {
        $sync = $this->service($this->settings(enabled: false));
        $this->assertNull($sync->start('manual', true));
        $this->assertSame('disabled', $sync->lastStartReason());
        $this->assertNull($this->st()['lock_run_id']);
        $this->assertSame([], $this->client->calls);
    }

    public function testFreshStartClearsTheLastErrorButAResumeOnlyMarksRunning(): void
    {
        $this->state->update(['last_status' => 'error', 'last_error_code' => 500, 'last_error_message' => 'boom']);
        $runId = $this->startRun();
        $s = $this->st();
        $this->assertSame('running', $s['last_status']);
        $this->assertNull($s['last_error_code']);
        $this->assertNull($s['last_error_message']);

        // Interrupted by 402, then resumed: the 402 detail stays visible while running.
        $this->client->script = [$this->failure(WokeometerPageResult::OUT_OF_CREDITS, 402)];
        $this->sync->runChunk($runId);
        $this->state->update(['next_attempt_after' => null]);
        $this->startRun('schedule');
        $s = $this->st();
        $this->assertSame('running', $s['last_status']);
        $this->assertSame(402, $s['last_error_code']);
    }

    public function testFreshStartAfterATerminalStopBeginsAtTheFirstPage(): void
    {
        // Loop guard = one of the two NON-resumable stops.
        $this->client->script = [$this->page(['m1'], 'c1'), $this->page(['m2'], 'c1')];
        $this->assertSame('halted', $this->sync->runChunk($this->startRun())->reason);
        $this->assertNull($this->st()['run_phase']);
        $this->assertNull($this->st()['run_idempotency_key']);

        $this->client->calls = [];
        $this->client->script = [$this->page(['m1'], null), $this->page([], null, 'tv')];
        $this->sync->clock = self::T0 + 50;
        $this->assertSame(WokeometerChunkResult::DONE, $this->sync->runChunk($this->startRun())->status);
        $this->assertSame([['movie', null], ['tv', null]], array_map(fn(array $c) => [$c['type'], $c['after']], $this->client->calls));
        $this->assertSame(self::T0 + 50, $this->st()['watermark']);
    }

    public function testForcedFullOverAStaleIncrementalRunConvertsItInPlace(): void
    {
        $done = self::T0 - 40 * 86_400;
        $this->state->update(['full_sync_completed_at' => $done, 'watermark' => $done, 'last_success_at' => $done]);
        $this->client->script = [$this->page(['m1'], 'c1'), $this->failure(WokeometerPageResult::RATE_LIMITED, 429, 5)];
        $inc = $this->startRun('schedule');
        $this->assertSame('incremental', $this->st()['run_mode']);
        $this->sync->runChunk($inc); // lock still held, then the consumer dies
        $this->state->update(['last_error_code' => 503, 'last_error_message' => 'old']);

        $later = self::T0 + WokeometerSyncService::STALE_LOCK_SECONDS + 60;
        $this->sync->clock = $later;
        $full = $this->startRun('manual', true);
        $s = $this->st();
        $this->assertSame($full, $s['lock_run_id']);
        $this->assertSame('full', $s['run_mode']);
        $this->assertSame('movie', $s['run_phase']);
        $this->assertNull($s['run_cursor']);
        $this->assertNull($s['run_idempotency_key']);
        $this->assertSame(0, $s['run_requests']);
        $this->assertSame(0, $s['run_records']);
        $this->assertSame(0, $s['run_transient_failures']);
        $this->assertSame($later, $s['run_started_at']);
        $this->assertSame('manual', $s['run_trigger']);
        $this->assertSame('running', $s['last_status']);
        $this->assertNull($s['last_error_code'], 'fresh semantics');
        $this->assertNull($s['last_error_message']);

        $this->client->calls = [];
        $this->client->script = [$this->page(['m1'], null), $this->page([], null, 'tv')];
        $this->assertSame(WokeometerChunkResult::DONE, $this->sync->runChunk($full)->status);
        $this->assertSame([['movie', null, null], ['tv', null, null]],
            array_map(fn(array $c) => [$c['type'], $c['after'], $c['updatedSince']], $this->client->calls));
        $s = $this->st();
        $this->assertSame($later, $s['watermark']);
        $this->assertSame($later, $s['full_sync_completed_at']);
    }

    public function testFullResyncRestartsAFreeInterruptedFullRunStuckOnAnInvalidPage(): void
    {
        // First full sync stops resumably on an unreadable page after c1.
        $this->client->script = [$this->page(['m1'], 'c1'), $this->failure(WokeometerPageResult::INVALID, 404)];
        $this->sync->runChunk($this->startRun());
        $this->state->update(['run_transient_failures' => 2]); // leftover budget must not survive the restart
        $s = $this->st();
        $this->assertSame('invalid', $s['last_status']);
        $this->assertSame('full', $s['run_mode']);
        $this->assertSame('c1', $s['run_cursor']);
        $this->assertNotNull($s['run_idempotency_key']);

        // A scheduled start (after the backoff) would resume the same stuck cursor…
        $later = self::T0 + 3600;
        $this->sync->clock = $later;
        // …but "Full resync" means start over (manual → bypasses the 7-day backoff).
        $runId = $this->startRun('manual', true);
        $s = $this->st();
        $this->assertSame($runId, $s['lock_run_id']);
        $this->assertSame('full', $s['run_mode']);
        $this->assertSame('movie', $s['run_phase']);
        $this->assertNull($s['run_cursor']);
        $this->assertNull($s['run_idempotency_key']);
        $this->assertSame($later, $s['run_started_at']);
        $this->assertSame(0, $s['run_requests']);
        $this->assertSame(0, $s['run_records']);
        $this->assertSame(0, $s['run_transient_failures']);
        $this->assertSame('running', $s['last_status']);
        $this->assertNull($s['last_error_code']);
        $this->assertNull($s['last_error_message']);

        $this->client->calls = [];
        $this->client->script = [$this->page(['m1'], null), $this->page([], null, 'tv')];
        $this->assertSame(WokeometerChunkResult::DONE, $this->sync->runChunk($runId)->status);
        $this->assertSame([['movie', null], ['tv', null]], array_map(fn(array $c) => [$c['type'], $c['after']], $this->client->calls));
        $this->assertSame($later, $this->st()['watermark']);
    }

    public function testFullSweepIsPerTypeAndSkipsAPhaseThatReturnedNothing(): void
    {
        $this->titles->upsertRows([$this->row('old-movie'), $this->row('old-show', 'tv')], self::T0 - 1000, self::T0 - 1000);

        $this->client->script = [$this->page([], null), $this->page(['t1'], null, 'tv')];
        $this->assertSame(WokeometerChunkResult::DONE, $this->sync->runChunk($this->startRun())->status);

        $wids = $this->db->fetchFirstColumn('SELECT wokeometer_id FROM wokeometer_media ORDER BY wokeometer_id');
        $this->assertSame(['old-movie', 't1'], $wids, 'empty movie phase: no movie deleted; tv phase swept');
    }

    public function testFullRunThatStoredNothingSkipsTheSweep(): void
    {
        $this->titles->upsertRows([$this->row('keep')], self::T0 - 1000, self::T0 - 1000);
        $this->client->script = [$this->page([], null), $this->page([], null, 'tv')];
        $this->assertSame(WokeometerChunkResult::DONE, $this->sync->runChunk($this->startRun())->status);
        $this->assertSame(1, $this->mediaCount(), 'an empty full catalog response never wipes the mirror');
        $this->assertSame(self::T0, $this->st()['full_sync_completed_at']);
    }

    public function testAScheduledStartResumesAnInterruptedForcedFullResync(): void
    {
        $done = self::T0 - 10 * 86_400;
        $this->state->update(['full_sync_completed_at' => $done, 'watermark' => $done, 'last_success_at' => $done]);
        $this->client->script = [$this->page(['m1'], 'c1'), $this->failure(WokeometerPageResult::OUT_OF_CREDITS, 402)];
        $this->sync->runChunk($this->startRun('manual', true));
        $this->state->update(['next_attempt_after' => null]);

        $this->startRun('schedule');
        $s = $this->st();
        $this->assertSame('full', $s['run_mode'], 'the interrupted full resync is not superseded by an incremental run');
        $this->assertSame('c1', $s['run_cursor']);
    }

    public function testRateLimitStormStopsResumablyAfterTenInARow(): void
    {
        $this->client->script = [$this->page(['m1'], 'c1')];
        for ($i = 0; $i < WokeometerSyncService::MAX_RATE_LIMIT_RETRIES; $i++) {
            $this->client->script[] = $this->failure(WokeometerPageResult::RATE_LIMITED, 429, 5);
        }
        $runId = $this->startRun();

        $statuses = [];
        for ($i = 0; $i < WokeometerSyncService::MAX_RATE_LIMIT_RETRIES; $i++) {
            $statuses[] = $this->sync->runChunk($runId)->status;
        }
        $this->assertSame(array_merge(array_fill(0, 9, 'continue'), ['stopped']), $statuses);
        $this->assertSame(10, WokeometerSyncService::MAX_RATE_LIMIT_RETRIES);

        $s = $this->st();
        $this->assertSame('error', $s['last_status']);
        $this->assertSame(429, $s['last_error_code']);
        $this->assertSame(self::T0 + WokeometerSyncService::TRANSIENT_BACKOFF_SECONDS, $s['next_attempt_after']);
        $this->assertSame('movie', $s['run_phase'], 'resumable');
        $this->assertSame('c1', $s['run_cursor']);
        $this->assertSame($this->client->calls[1]['key'], $s['run_idempotency_key'], 'same key: the throttled page replays for free');
        $this->assertSame(0, $s['run_transient_failures'], 'fresh budget on resume');
        $this->assertNull($s['lock_run_id']);
    }

    public function testRateLimitCounterResetsOnAnOkPage(): void
    {
        $script = [];
        for ($i = 0; $i < 9; $i++) {
            $script[] = $this->failure(WokeometerPageResult::RATE_LIMITED, 429, 1);
        }
        $script[] = $this->page(['m1'], 'c1');
        for ($i = 0; $i < 9; $i++) {
            $script[] = $this->failure(WokeometerPageResult::RATE_LIMITED, 429, 1);
        }
        $script[] = $this->page(['m2'], null);
        $script[] = $this->page([], null, 'tv');
        $this->client->script = $script;
        $runId = $this->startRun();

        $last = null;
        for ($i = 0; $i < 30 && $last !== 'done' && $last !== 'stopped'; $i++) {
            $last = $this->sync->runChunk($runId)->status;
        }
        $this->assertSame('done', $last, 'nine 429s, an OK page, nine more: never ten in a row');
    }

    public function testDisablingBetweenPagesStopsWithinOnePage(): void
    {
        $enabled   = true;
        $refreshes = 0;
        $settings  = $this->createStub(WokeometerSettings::class);
        $settings->method('isEnabled')->willReturnCallback(function () use (&$enabled): bool { return $enabled; });
        $settings->method('isAutoSyncEnabled')->willReturn(true);
        $settings->method('apiKey')->willReturn('wok_test');
        $settings->method('refresh')->willReturnCallback(function () use (&$refreshes): void { $refreshes++; });
        $this->sync = $this->service($settings);

        $this->client->script = [$this->page(['m1'], 'c1'), $this->page(['m2'], 'c2'), $this->page(['m3'], 'c3')];
        // The admin switches Wokeometer off while page 1 is in flight.
        $this->client->afterCall = function () use (&$enabled): void { $enabled = false; };
        $runId = $this->startRun();

        $r = $this->sync->runChunk($runId);
        $this->assertSame(WokeometerChunkResult::STOPPED, $r->status);
        $this->assertSame('error', $r->reason);
        $this->assertCount(1, $this->client->calls, 'no page after the switch-off');
        $s = $this->st();
        $this->assertSame('disabled mid-run', $s['last_error_message']);
        $this->assertSame(self::T0 + WokeometerSyncService::TRANSIENT_BACKOFF_SECONDS, $s['next_attempt_after']);
        $this->assertSame('movie', $s['run_phase'], 'resumable');
        $this->assertSame('c1', $s['run_cursor'], 'page 1 committed');
        $this->assertNull($s['lock_run_id']);
        $this->assertSame(2, $refreshes, 'settings re-read before every page');
    }

    public function testARunThatLostTheLockWritesNothing(): void
    {
        // Steals the lock right before the page commit (a stale takeover racing the write).
        $stealing = new class(self::getContainer()->get('doctrine')) extends WokeometerSyncStateRepository {
            public bool $armed = false;

            public function update(array $fields, ?string $ownerRunId = null): int
            {
                if ($this->armed && $ownerRunId !== null && array_key_exists('run_cursor', $fields)) {
                    $this->armed = false;
                    $this->getEntityManager()->getConnection()->executeStatement(
                        "UPDATE wokeometer_sync_state SET lock_run_id = 'thief', lock_heartbeat_at = 1 WHERE id = 1",
                    );
                }
                return parent::update($fields, $ownerRunId);
            }
        };
        $this->state = $stealing;
        $this->sync  = $this->service();

        $this->client->script = [$this->page(['m1'], 'c1'), $this->page(['m2'], 'c2')];
        $runId = $this->startRun();
        $stealing->armed = true;

        $r = $this->sync->runChunk($runId);
        $this->assertSame('not_owner', $r->reason);
        $this->assertCount(1, $this->client->calls, 'stopped at once, no second page');
        $s = $this->st();
        $this->assertSame('thief', $s['lock_run_id'], 'the successor keeps its lock');
        $this->assertNull($s['run_cursor'], 'the page commit did not land');
        // The billing facts were written while this run still held the lock
        // (before the theft): the page WAS billed, so it is accounted.
        $this->assertSame(1, $s['run_requests']);
        $this->assertSame(1, $s['total_requests']);
        $this->assertSame('running', $s['last_status'], 'no stop was recorded either');
        $this->assertSame($this->client->calls[0]['key'], $s['run_idempotency_key'], 'the successor replays the persisted key');
    }

    public function testStartReleasesTheLockWhenAWriteAfterAcquiringThrows(): void
    {
        $failing = new class(self::getContainer()->get('doctrine')) extends WokeometerSyncStateRepository {
            public function update(array $fields, ?string $ownerRunId = null): int
            {
                if (($fields['last_status'] ?? null) === 'running') {
                    throw new \RuntimeException('disk full');
                }
                return parent::update($fields, $ownerRunId);
            }
        };
        $this->state = $failing;
        $this->sync  = $this->service();

        try {
            $this->sync->start('manual', false);
            $this->fail('expected the write failure to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('disk full', $e->getMessage());
        }
        $this->assertNull($this->st()['lock_run_id'], 'lock released: the next click is not "locked" for 30 minutes');
    }

    public function testManualStartClearsAnActiveBackoff(): void
    {
        $this->state->update(['next_attempt_after' => self::T0 + 86_400, 'last_status' => 'auth']);
        $this->startRun('manual');
        $this->assertNull($this->st()['next_attempt_after']);
    }

    public function testAContinuationOfAFinishedRunOnlyReleasesTheLock(): void
    {
        $runId = $this->startRun();
        $this->state->update(['run_phase' => 'done']);

        $r = $this->sync->runChunk($runId);
        $this->assertSame(WokeometerChunkResult::DONE, $r->status);
        $this->assertSame([], $this->client->calls);
        $s = $this->st();
        $this->assertNull($s['lock_run_id']);
        $this->assertSame('running', $s['last_status'], 'no error recorded');
    }

    // ── isDue matrix ──────────────────────────────────────────────────

    public function testIsDueMatrix(): void
    {
        $now = self::T0;
        $this->assertFalse($this->sync->isDue($now), 'never synced: the initial full sync is manual-only');
        $this->assertNull($this->sync->start('schedule', false, $now));
        $this->assertSame('not_due', $this->sync->lastStartReason());
        $this->assertSame([], $this->client->calls);

        // …but an interrupted initial sync (started by the admin) is resumed.
        $this->state->update(['run_phase' => 'tv', 'run_mode' => 'full', 'run_cursor' => 'd1']);
        $this->assertTrue($this->sync->isDue($now), 'never synced, interrupted run waiting');
        $this->state->update(['run_phase' => 'done']);
        $this->assertFalse($this->sync->isDue($now), 'never synced, finished-but-unrecorded run');
        $this->state->update(['run_phase' => null, 'run_cursor' => null]);

        $this->state->update(['full_sync_completed_at' => $now - 3600, 'last_success_at' => $now - 3600]);
        $this->assertFalse($this->sync->isDue($now), 'synced an hour ago');

        $this->state->update(['last_success_at' => $now - 30 * 86_400]);
        $this->assertTrue($this->sync->isDue($now), 'exactly 30 days');

        $this->state->update(['next_attempt_after' => $now + 1]);
        $this->assertFalse($this->sync->isDue($now), 'backoff');
        $this->state->update(['next_attempt_after' => $now]);
        $this->assertTrue($this->sync->isDue($now), 'backoff expired');

        foreach (['request_cap', 'halted', 'invalid'] as $paused) {
            $this->state->update(['last_status' => $paused]);
            $this->assertFalse($this->sync->isDue($now), "paused after $paused");
            $this->assertFalse($this->sync->isDue($now + 365 * 86_400), "still paused after $paused");
        }
        foreach (['ok', 'error', 'out_of_credits', 'running', null] as $status) {
            $this->state->update(['last_status' => $status]);
            $this->assertTrue($this->sync->isDue($now), 'not paused by ' . var_export($status, true));
        }

        $this->assertFalse($this->service($this->settings(autoSync: false))->isDue($now), 'auto-sync off');
        $this->assertFalse($this->service($this->settings(enabled: false))->isDue($now), 'disabled');

        $this->state->acquireLock('live', $now - 60, $now - 1800, 'incremental', 'schedule');
        $this->assertFalse($this->sync->isDue($now), 'a live run holds the lock');
        $this->assertTrue($this->sync->isDue($now + 1800), 'stale lock → takeover');

        $this->state->releaseLock('live');
        $this->state->update(['last_success_at' => $now - 3600, 'run_phase' => 'movie', 'run_mode' => 'incremental']);
        $this->assertTrue($this->sync->isDue($now), 'resumable interrupted run');
        $this->state->update(['run_phase' => 'done']);
        $this->assertFalse($this->sync->isDue($now), 'finished run, not yet 30 days');

        // Crash between the final write and the lock release: a stale lock on
        // a FINISHED run must not force an extra billed run.
        $this->state->acquireLock('crashed', $now - 3600, $now - 3600 - 1800, 'incremental', 'schedule');
        $this->state->update(['run_phase' => 'done']);
        $this->assertFalse($this->sync->isDue($now), 'stale lock on a finished run, not yet 30 days');
        $this->state->update(['last_success_at' => $now - 31 * 86_400]);
        $this->assertTrue($this->sync->isDue($now), 'stale lock on a finished run, overdue');
    }

    // ── statusSummary ─────────────────────────────────────────────────

    public function testStatusSummaryShape(): void
    {
        $this->client->script = [$this->page(['m1', 'm2'], null), $this->page(['t1'], null, 'tv')];
        $this->sync->runChunk($this->startRun());

        $sum = $this->sync->statusSummary(fn() => 7);
        $this->assertSame([
            'enabled', 'configured', 'autoSync', 'running', 'runMode', 'runPhase', 'runRequests', 'runRecords',
            'lastStatus', 'lastErrorMessage', 'lastSuccessAt', 'lastRunStartedAt', 'lastRunFinishedAt',
            'lastRunRequests', 'lastRunRecords', 'totalRequests', 'creditsRemaining', 'creditsRemainingAt',
            'nextAttemptAfter', 'fullSyncCompletedAt', 'watermark', 'nextDueAt', 'cached', 'matchedTitles',
        ], array_keys($sum));
        $this->assertTrue($sum['enabled']);
        $this->assertTrue($sum['configured']);
        $this->assertFalse($sum['running']);
        $this->assertSame('ok', $sum['lastStatus']);
        $this->assertSame(self::T0 + 30 * 86_400, $sum['nextDueAt']);
        $this->assertSame(['total' => 3, 'movies' => 2, 'series' => 1, 'seasons' => 0, 'withTmdb' => 3], $sum['cached']);
        $this->assertSame(7, $sum['matchedTitles']);
        $this->assertSame(2, $sum['totalRequests']);

        $this->assertNull($this->sync->statusSummary()['matchedTitles']);
        $this->assertNull($this->sync->statusSummary(fn() => throw new \RuntimeException('cold'))['matchedTitles']);
    }

    public function testStatusSummaryRunningAndNeverSynced(): void
    {
        $sum = $this->sync->statusSummary();
        $this->assertNull($sum['nextDueAt']);
        $this->assertFalse($sum['running']);

        $this->startRun();
        $this->assertTrue($this->sync->statusSummary()['running']);
        $this->sync->clock = self::T0 + WokeometerSyncService::STALE_LOCK_SECONDS + 1;
        $this->assertFalse($this->sync->statusSummary()['running'], 'a stale lock is not "running"');
    }

    public function testStatusSummaryHidesNextDueWhilePaused(): void
    {
        $this->state->update(['full_sync_completed_at' => self::T0 - 3600, 'last_success_at' => self::T0 - 3600, 'last_status' => 'ok']);
        $this->assertSame(self::T0 - 3600 + 30 * 86_400, $this->sync->statusSummary()['nextDueAt']);

        foreach (['request_cap', 'halted'] as $paused) {
            $this->state->update(['last_status' => $paused]);
            $this->assertNull($this->sync->statusSummary()['nextDueAt'], "no next-due date while paused after $paused");
        }
    }
    // ── billing hardening ─────────────────────────────────────────────

    public function testAUuidCursorThatMovesBackwardsHaltsTheRunBeforeAnotherPaidRequest(): void
    {
        // Results are sorted by UUID ascending and the cursor is the last
        // row's UUID, so each next_cursor must sort after the previous one.
        // The old guard only caught A→A; an A→B→A cycle (provider bug) would
        // have spent up to the 600-request cap per run.
        $u1 = '00000000-0000-4000-8000-000000000001';
        $u2 = '00000000-0000-4000-8000-000000000002';
        $this->client->script = [$this->page(['m1'], $u2), $this->page(['m2'], $u1), $this->page(['m3'], null)];
        $r = $this->sync->runChunk($this->startRun());

        $this->assertSame(WokeometerSyncService::STATUS_HALTED, $r->reason);
        $this->assertCount(2, $this->client->calls, 'no third paid request');
        $this->assertSame('cursor did not advance', $this->st()['last_error_message']);
    }

    public function testAnUnreadableResponsePausesAutomaticSyncButAManualStartResumesIt(): void
    {
        // An unusable 2xx is billed: resuming automatically every 7 days re-bills
        // the same unusable page forever. Pause until someone looks.
        $this->client->script = [$this->page(['m1'], 'c1'), new WokeometerPageResult(WokeometerPageResult::INVALID, 200, message: 'unusable body')];
        $this->sync->runChunk($this->startRun());
        $this->assertSame('invalid', $this->st()['last_status']);

        $this->assertFalse($this->sync->isDue(self::T0 + WokeometerSyncService::INVALID_BACKOFF_SECONDS + 1), 'no automatic re-bill');
        $this->sync->clock = self::T0 + WokeometerSyncService::INVALID_BACKOFF_SECONDS + 1;
        $this->assertNull($this->sync->start('schedule', false));

        $this->client->calls  = [];
        $this->client->script = [$this->page(['m2'], null), $this->page([], null, 'tv')];
        $this->assertSame(WokeometerChunkResult::DONE, $this->sync->runChunk($this->startRun('manual'))->status);
        $this->assertSame('c1', $this->client->calls[0]['after'], 'a manual start resumes from the saved cursor');
    }

    public function testABilledPageIsAccountedEvenWhenStoringItsRowsThrows(): void
    {
        // The request was billed; a failure afterwards must not lose that
        // from run_requests / total_requests (the 600 cap counts it).
        $titles = $this->createStub(WokeometerTitleRepository::class);
        $titles->method('upsertRows')->willThrowException(new \RuntimeException('disk full'));
        $settings     = $this->settings();
        $this->client = new ScriptedWokeometerClient($settings, new NullLogger(), $this->state);
        $this->sync   = new TestableWokeometerSyncService($settings, $this->client, $titles, $this->state, new NullLogger());
        $this->sync->clock = self::T0;

        $this->client->script = [$this->page(['m1'], 'c1', credits: 41)];
        $r = $this->sync->runChunk($this->startRun());

        $this->assertSame('error', $r->reason);
        $s = $this->st();
        $this->assertSame(1, $s['run_requests']);
        $this->assertSame(1, $s['total_requests']);
        $this->assertSame(41, $s['credits_remaining']);
    }
}

/**
 * Scripted client: pops one result (or throwable) per call and records the
 * call, including whether the Idempotency-Key was already in the state row.
 */
final class ScriptedWokeometerClient extends WokeometerClient
{
    /** @var list<WokeometerPageResult|\Throwable> */
    public array $script = [];

    /** @var list<array{type: string, after: ?string, updatedSince: ?int, key: string, keyPersisted: bool}> */
    public array $calls = [];

    /** Runs after each call (e.g. a settings change saved while the page is in flight). */
    public ?\Closure $afterCall = null;

    public function __construct(WokeometerSettings $settings, LoggerInterface $logger, private readonly WokeometerSyncStateRepository $state)
    {
        parent::__construct($settings, $logger);
    }

    public function listMedia(string $type, ?string $after, ?int $updatedSinceEpoch, string $idempotencyKey): WokeometerPageResult
    {
        $this->calls[] = [
            'type'         => $type,
            'after'        => $after,
            'updatedSince' => $updatedSinceEpoch,
            'key'          => $idempotencyKey,
            'keyPersisted' => $this->state->get()['run_idempotency_key'] === $idempotencyKey,
        ];
        $next = array_shift($this->script);
        if ($this->afterCall !== null) {
            ($this->afterCall)();
        }
        if ($next === null) {
            throw new \LogicException('ScriptedWokeometerClient: script exhausted');
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return $next;
    }
}

final class TestableWokeometerSyncService extends WokeometerSyncService
{
    public int $clock = 0;
    public int $pauses = 0;
    /** @var list<int> */
    public array $pausedMicros = [];

    protected function now(): int
    {
        return $this->clock;
    }

    protected function pause(int $micros): void
    {
        $this->pauses++;
        $this->pausedMicros[] = $micros;
    }
}
