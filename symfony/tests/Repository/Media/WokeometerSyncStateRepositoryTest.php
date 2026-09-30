<?php

namespace App\Tests\Repository\Media;

use App\Entity\Media\WokeometerSyncState;
use App\Repository\Media\WokeometerSyncStateRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Real-SQLite tests for the single-row Wokeometer sync state (lock, cursor,
 * watermark, stats). SchemaTool builds the table from the entity, so the
 * migration's seed row does NOT exist here — the repository must self-seed.
 */
class WokeometerSyncStateRepositoryTest extends KernelTestCase
{
    private WokeometerSyncStateRepository $repo;
    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get('doctrine')->getManager();
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $repo = $em->getRepository(WokeometerSyncState::class);
        $this->assertInstanceOf(WokeometerSyncStateRepository::class, $repo);
        $this->repo = $repo;
        $this->db = $em->getConnection();
    }

    private function rowCount(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM wokeometer_sync_state');
    }

    public function testEnsureRowIsIdempotent(): void
    {
        $this->assertSame(0, $this->rowCount());
        $this->repo->ensureRow();
        $this->repo->ensureRow();
        $this->assertSame(1, $this->rowCount());
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT id FROM wokeometer_sync_state'));
    }

    public function testGetSelfSeedsAndReturnsDefaultsWithIntCasts(): void
    {
        $s = $this->repo->get();

        $this->assertSame(1, $this->rowCount());
        $this->assertSame(1, $s['id']);
        $this->assertSame(0, $s['run_requests']);
        $this->assertSame(0, $s['run_records']);
        $this->assertSame(0, $s['run_transient_failures']);
        $this->assertSame(0, $s['total_requests']);
        $this->assertNull($s['watermark']);
        $this->assertNull($s['lock_run_id']);
        $this->assertNull($s['run_phase']);
        $this->assertNull($s['credits_remaining']);
        $this->assertArrayHasKey('next_attempt_after', $s);
        $this->assertArrayHasKey('run_trigger', $s);
    }

    public function testUpdateWritesWhitelistedColumnsWithTypes(): void
    {
        $this->repo->update([
            'watermark'          => 1_700_000_000,
            'last_status'        => 'ok',
            'last_error_message' => "O'Brien; DROP TABLE x",
            'credits_remaining'  => 42,
            'run_cursor'         => null,
        ]);

        $s = $this->repo->get();
        $this->assertSame(1_700_000_000, $s['watermark']);
        $this->assertSame('ok', $s['last_status']);
        $this->assertSame("O'Brien; DROP TABLE x", $s['last_error_message'], 'values are parameter-bound');
        $this->assertSame(42, $s['credits_remaining']);
        $this->assertNull($s['run_cursor']);

        $this->repo->update([]); // no-op
        $this->assertSame(42, $this->repo->get()['credits_remaining']);
    }

    public function testUpdateRejectsUnknownColumns(): void
    {
        $this->repo->update(['watermark' => 5]);

        try {
            $this->repo->update(['watermark' => 9, 'watermark = 0; --' => 1]);
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->repo->update(['id' => 2]);
        } finally {
            $this->assertSame(5, $this->repo->get()['watermark'], 'a rejected update writes nothing');
            $this->assertSame(1, $this->rowCount());
        }
    }

    public function testFreshAcquireInitialisesRun(): void
    {
        $this->repo->update(['run_requests' => 7, 'run_cursor' => 'stale-cursor', 'run_phase' => 'done', 'run_transient_failures' => 2]);

        $this->assertSame('fresh', $this->repo->acquireLock('run-1', 1000, 1000 - 1800, 'full', 'manual'));

        $s = $this->repo->get();
        $this->assertSame('run-1', $s['lock_run_id']);
        $this->assertSame(1000, $s['lock_heartbeat_at']);
        $this->assertSame('full', $s['run_mode']);
        $this->assertSame('manual', $s['run_trigger']);
        $this->assertSame(1000, $s['run_started_at']);
        $this->assertSame('movie', $s['run_phase']);
        $this->assertNull($s['run_cursor']);
        $this->assertNull($s['run_idempotency_key']);
        $this->assertSame(0, $s['run_requests']);
        $this->assertSame(0, $s['run_records']);
        $this->assertSame(0, $s['run_transient_failures']);
        $this->assertSame(1000, $s['last_run_started_at']);
    }

    public function testTryAcquireLockDeniedWhileHeldAndFresh(): void
    {
        $this->assertTrue($this->repo->tryAcquireLock('run-1', 1000, 1000 - 1800, 'full', 'manual'));

        $this->assertFalse($this->repo->tryAcquireLock('run-2', 1100, 1100 - 1800, 'incremental', 'schedule'));
        $this->assertNull($this->repo->acquireLock('run-2', 1100, 1100 - 1800, 'incremental', 'schedule'));

        $s = $this->repo->get();
        $this->assertSame('run-1', $s['lock_run_id']);
        $this->assertSame('full', $s['run_mode']);
        $this->assertSame('manual', $s['run_trigger']);
    }

    public function testStaleTakeoverKeepsCursorAndRunState(): void
    {
        $this->repo->tryAcquireLock('run-1', 1000, 1000 - 1800, 'full', 'manual');
        $this->repo->update([
            'run_phase'           => 'tv',
            'run_cursor'          => 'uuid-cursor',
            'run_idempotency_key' => 'idem-key',
            'run_requests'        => 12,
            'run_records'         => 600,
        ]);

        // Heartbeat at 1000; at now=4000 with a 30-min window the lock is stale.
        $this->assertSame('resumed', $this->repo->acquireLock('run-2', 4000, 4000 - 1800, 'incremental', 'schedule'));

        $s = $this->repo->get();
        $this->assertSame('run-2', $s['lock_run_id']);
        $this->assertSame(4000, $s['lock_heartbeat_at']);
        $this->assertSame('schedule', $s['run_trigger']);
        $this->assertSame('full', $s['run_mode'], 'resume keeps the original mode');
        $this->assertSame('tv', $s['run_phase']);
        $this->assertSame('uuid-cursor', $s['run_cursor']);
        $this->assertSame('idem-key', $s['run_idempotency_key']);
        $this->assertSame(1000, $s['run_started_at']);
        $this->assertSame(12, $s['run_requests']);
        $this->assertSame(600, $s['run_records']);
    }

    public function testFreeLockWithInterruptedRunResumes(): void
    {
        $this->repo->tryAcquireLock('run-1', 1000, 1000 - 1800, 'full', 'manual');
        $this->repo->update(['run_phase' => 'movie', 'run_cursor' => 'c-9', 'run_requests' => 3]);
        $this->repo->releaseLock('run-1'); // e.g. stopped on out_of_credits, cursor kept

        $this->assertSame('resumed', $this->repo->acquireLock('run-2', 90_000, 90_000 - 1800, 'full', 'schedule'));

        $s = $this->repo->get();
        $this->assertSame('run-2', $s['lock_run_id']);
        $this->assertSame('c-9', $s['run_cursor']);
        $this->assertSame(3, $s['run_requests']);
        $this->assertSame(1000, $s['run_started_at']);
    }

    public function testStaleLockOnFinishedRunStartsFresh(): void
    {
        $this->repo->tryAcquireLock('run-1', 1000, 1000 - 1800, 'full', 'manual');
        $this->repo->update(['run_phase' => 'done', 'run_cursor' => 'x', 'run_requests' => 5]);

        $this->assertSame('fresh', $this->repo->acquireLock('run-2', 4000, 4000 - 1800, 'incremental', 'schedule'));

        $s = $this->repo->get();
        $this->assertSame('run-2', $s['lock_run_id']);
        $this->assertSame('incremental', $s['run_mode']);
        $this->assertSame('movie', $s['run_phase']);
        $this->assertNull($s['run_cursor']);
        $this->assertSame(0, $s['run_requests']);
        $this->assertSame(4000, $s['run_started_at']);
    }

    /** Leave a free (released) but unfinished run of $mode behind, as a stop on out_of_credits would. */
    private function interruptedRun(string $mode): void
    {
        $this->assertSame('fresh', $this->repo->acquireLock('run-1', 1000, 1000 - 1800, $mode, 'manual'));
        $this->repo->update([
            'run_phase'              => 'tv',
            'run_cursor'             => 'c-42',
            'run_idempotency_key'    => 'idem-1',
            'run_requests'           => 9,
            'run_records'            => 450,
            'run_transient_failures' => 1,
        ]);
        $this->assertTrue($this->repo->releaseLock('run-1'));
    }

    private function assertFreshRun(string $runId, string $mode, int $now): void
    {
        $s = $this->repo->get();
        $this->assertSame($runId, $s['lock_run_id']);
        $this->assertSame($mode, $s['run_mode']);
        $this->assertSame('movie', $s['run_phase']);
        $this->assertNull($s['run_cursor']);
        $this->assertNull($s['run_idempotency_key']);
        $this->assertSame(0, $s['run_requests']);
        $this->assertSame(0, $s['run_records']);
        $this->assertSame(0, $s['run_transient_failures']);
        $this->assertSame($now, $s['run_started_at']);
        $this->assertSame($now, $s['last_run_started_at']);
    }

    public function testFreeInterruptedIncrementalIsSupersededByForcedFull(): void
    {
        $this->interruptedRun('incremental');

        $this->assertSame('fresh', $this->repo->acquireLock('run-2', 90_000, 90_000 - 1800, 'full', 'manual', true));

        $this->assertFreshRun('run-2', 'full', 90_000);
    }

    public function testFreeInterruptedFullIsSupersededByForcedFull(): void
    {
        // Same mode, but forced: "Full resync" means start over (decided in the CAS).
        $this->interruptedRun('full');

        $this->assertSame('fresh', $this->repo->acquireLock('run-2', 90_000, 90_000 - 1800, 'full', 'manual', true));

        $this->assertFreshRun('run-2', 'full', 90_000);
    }

    public function testNonForcedStartOfAnyModeResumesAFreeInterruptedRunInItsOwnMode(): void
    {
        foreach ([['full', 'incremental'], ['incremental', 'full'], ['full', 'full']] as [$runMode, $requested]) {
            $this->repo->update(['run_phase' => null]);
            $this->interruptedRun($runMode);

            $this->assertSame('resumed', $this->repo->acquireLock('run-2', 90_000, 90_000 - 1800, $requested, 'schedule'), "$runMode ← $requested");

            $s = $this->repo->get();
            $this->assertSame($runMode, $s['run_mode'], 'the run keeps its own mode');
            $this->assertSame('c-42', $s['run_cursor']);
            $this->assertSame('idem-1', $s['run_idempotency_key']);
            $this->assertSame(1000, $s['run_started_at']);
            $this->assertTrue($this->repo->releaseLock('run-2'));
        }
    }

    public function testForcedStartOverAStaleHeldRunStillResumesSoTheCallerCanConvertIt(): void
    {
        $this->assertSame('fresh', $this->repo->acquireLock('run-1', 1000, 1000 - 1800, 'incremental', 'schedule'));
        $this->repo->update(['run_phase' => 'tv', 'run_cursor' => 'c-1']);

        $this->assertSame('resumed', $this->repo->acquireLock('run-2', 4000, 4000 - 1800, 'full', 'manual', true));
        $this->assertSame('run-2', $this->repo->get()['lock_run_id']);
        $this->assertSame('c-1', $this->repo->get()['run_cursor']);
    }

    public function testFreeUnfinishedRowWithoutAModeStartsFreshInsteadOfWedging(): void
    {
        $this->repo->update(['run_phase' => 'movie', 'run_mode' => null, 'run_cursor' => 'x']);

        $this->assertSame('fresh', $this->repo->acquireLock('run-2', 90_000, 90_000 - 1800, 'incremental', 'schedule'));
        $this->assertFreshRun('run-2', 'incremental', 90_000);
    }

    public function testOwnerCheckedUpdateOnlyLandsForTheLockHolder(): void
    {
        $this->repo->tryAcquireLock('run-1', 1000, 1000 - 1800, 'full', 'manual');

        $this->assertSame(1, $this->repo->update(['run_cursor' => 'mine'], 'run-1'));
        $this->assertSame('mine', $this->repo->get()['run_cursor']);

        // Taken over (stale): the old run's writes change nothing.
        $this->assertSame('resumed', $this->repo->acquireLock('run-2', 4000, 4000 - 1800, 'full', 'schedule'));
        $this->assertSame(0, $this->repo->update(['run_cursor' => 'stale-write', 'last_status' => 'ok'], 'run-1'));
        $s = $this->repo->get();
        $this->assertSame('mine', $s['run_cursor']);
        $this->assertNull($s['last_status']);

        // Released: an owner write never lands on a free row either.
        $this->repo->releaseLock('run-2');
        $this->assertSame(0, $this->repo->update(['run_cursor' => 'late'], 'run-2'));
        $this->assertSame('mine', $this->repo->get()['run_cursor']);

        // Ownerless (controller / settings) writes still work and report 1.
        $this->assertSame(1, $this->repo->update(['next_attempt_after' => null]));
        $this->assertSame(0, $this->repo->update([], 'run-2'));
    }

    public function testFreeInterruptedIncrementalResumesForIncremental(): void
    {
        $this->interruptedRun('incremental');

        $this->assertSame('resumed', $this->repo->acquireLock('run-2', 90_000, 90_000 - 1800, 'incremental', 'schedule'));

        $s = $this->repo->get();
        $this->assertSame('run-2', $s['lock_run_id']);
        $this->assertSame('incremental', $s['run_mode']);
        $this->assertSame('tv', $s['run_phase']);
        $this->assertSame('c-42', $s['run_cursor']);
        $this->assertSame('idem-1', $s['run_idempotency_key']);
        $this->assertSame(9, $s['run_requests']);
        $this->assertSame(450, $s['run_records']);
        $this->assertSame(1, $s['run_transient_failures']);
        $this->assertSame(1000, $s['run_started_at']);
        $this->assertSame('schedule', $s['run_trigger']);
    }

    public function testUpdateCannotTouchLockColumns(): void
    {
        $this->repo->tryAcquireLock('run-1', 1000, 1000 - 1800, 'full', 'manual');

        foreach (['lock_run_id' => 'run-evil', 'lock_heartbeat_at' => 99_999] as $col => $value) {
            try {
                $this->repo->update([$col => $value]);
                $this->fail("update() must reject lock column $col");
            } catch (\InvalidArgumentException) {
            }
        }

        $s = $this->repo->get();
        $this->assertSame('run-1', $s['lock_run_id']);
        $this->assertSame(1000, $s['lock_heartbeat_at']);
    }

    /**
     * get() must be read-only once the row exists (INSERT OR IGNORE would take
     * SQLite's write lock on every settings-card status poll). Verified with a
     * spy subclass counting ensureRow() calls: one seed on the first read of an
     * empty table, none afterwards.
     */
    public function testGetOnlySeedsOnMiss(): void
    {
        $spy = new class(self::getContainer()->get('doctrine')) extends WokeometerSyncStateRepository {
            public int $ensureCalls = 0;

            public function ensureRow(): void
            {
                $this->ensureCalls++;
                parent::ensureRow();
            }
        };

        $this->assertSame(0, $this->rowCount());
        $this->assertSame(1, $spy->get()['id']);
        $this->assertSame(1, $spy->ensureCalls, 'first read of an empty table seeds the row');

        $spy->get();
        $spy->get();
        $this->assertSame(1, $spy->ensureCalls, 'later reads perform no INSERT');
        $this->assertSame(1, $this->rowCount());

        // Self-heal: a hand-deleted row is re-seeded on the next read.
        $this->db->executeStatement('DELETE FROM wokeometer_sync_state');
        $this->assertSame(0, $spy->get()['total_requests']);
        $this->assertSame(2, $spy->ensureCalls);
    }

    public function testHeartbeatOnlyForHolder(): void
    {
        $this->repo->tryAcquireLock('run-1', 1000, 1000 - 1800, 'full', 'manual');

        $this->assertFalse($this->repo->heartbeat('run-other', 1500));
        $this->assertSame(1000, $this->repo->get()['lock_heartbeat_at']);

        $this->assertTrue($this->repo->heartbeat('run-1', 1500));
        $this->assertSame(1500, $this->repo->get()['lock_heartbeat_at']);

        // A fresh heartbeat keeps the lock from being considered stale.
        $this->assertFalse($this->repo->tryAcquireLock('run-2', 3000, 3000 - 1800, 'full', 'manual'));
    }

    public function testReleaseByOtherRunIsNoop(): void
    {
        $this->repo->tryAcquireLock('run-1', 1000, 1000 - 1800, 'full', 'manual');

        $this->assertFalse($this->repo->releaseLock('run-2'));
        $this->assertSame('run-1', $this->repo->get()['lock_run_id']);

        $this->assertTrue($this->repo->releaseLock('run-1'));
        $s = $this->repo->get();
        $this->assertNull($s['lock_run_id']);
        $this->assertNull($s['lock_heartbeat_at']);
        $this->assertSame('movie', $s['run_phase'], 'release keeps the resumable run state');
    }
}
