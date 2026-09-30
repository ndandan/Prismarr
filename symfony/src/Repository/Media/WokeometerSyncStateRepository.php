<?php

namespace App\Repository\Media;

use App\Entity\Media\WokeometerSyncState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Single-row (id = 1) Wokeometer sync state. Raw, parameter-bound DBAL only
 * (no ORM identity map in the long-lived messenger consumer).
 *
 * The row is seeded by the migration AND `INSERT OR IGNORE`d here before
 * every write that needs it (tests build the schema with SchemaTool, which
 * never runs the migration seed; it also self-heals a deleted row). get()
 * is read-only on the hot path: it SELECTs first and only seeds on a miss,
 * so the settings-card status poll never takes SQLite's write lock while
 * the worker is syncing.
 *
 * Run lock (spec D4) — compare-and-set on the row, no symfony/lock. The
 * lock is "available" when it is free (`lock_run_id IS NULL`) or stale
 * (`lock_heartbeat_at` older than `$staleBefore`; a held lock with a NULL
 * heartbeat is also treated as stale — unreachable through this API, since
 * only acquireLock/heartbeat/releaseLock write the lock columns and they
 * always set/clear both together, but it self-heals a hand-edited row). An
 * available lock is then taken in one of two ways, reported by
 * acquireLock(); "unfinished" means `run_phase` non-null and not 'done':
 *
 *  - 'resumed' — an unfinished run that is either (a) a stale crashed run
 *    (lock still held) — resumed whatever `$mode` / `$forceFull` was
 *    requested (a forced full start then converts it in place while holding
 *    the lock) — or (b) a free, interrupted run (a resumable stop kept its
 *    cursor) when the start is NOT forced, whatever `$mode` was requested:
 *    the run continues in its OWN `run_mode`. Only `lock_run_id`,
 *    `lock_heartbeat_at` and `run_trigger` are replaced; `run_mode`,
 *    `run_phase`, `run_cursor`, `run_idempotency_key`, `run_started_at`,
 *    `run_requests`, `run_records` and `run_transient_failures` are kept so
 *    the run continues where it stopped (with the same idempotency key →
 *    free replay of the in-flight page).
 *  - 'fresh' — no unfinished run (`run_phase` null or 'done'), OR a free,
 *    interrupted run superseded by a FORCED full start (atomically, in the
 *    same UPDATE), OR a free unfinished row without a usable `run_mode`
 *    (malformed — it must not wedge both branches): a new run is initialised
 *    with the given mode/trigger, `run_started_at = last_run_started_at =
 *    $now`, phase 'movie', cursor/idempotency key cleared, counters zeroed.
 *
 * Resume-vs-fresh is decided HERE, inside the compare-and-set, never from a
 * state row the caller read earlier (read-then-decide races other starters).
 *
 * Each branch is a single conditional UPDATE, so two concurrent acquirers
 * can never both win: whichever UPDATE lands first changes `lock_run_id`,
 * and the other's WHERE no longer matches.
 *
 * Owner-checked writes: update($fields, $runId) adds `AND lock_run_id = ?`
 * and returns the affected-row count, so a run that lost the lock (stale
 * takeover) can never overwrite its successor's state — the sync service
 * treats 0 as "not the owner any more" and stops writing.
 *
 * @extends ServiceEntityRepository<WokeometerSyncState>
 */
class WokeometerSyncStateRepository extends ServiceEntityRepository
{
    public const ACQUIRED_FRESH   = 'fresh';
    public const ACQUIRED_RESUMED = 'resumed';

    /** Columns returned by get() as strings; every other column is cast to int. */
    private const STRING_COLUMNS = [
        'lock_run_id', 'run_mode', 'run_trigger', 'run_phase', 'run_cursor',
        'run_idempotency_key', 'last_status', 'last_error_message',
    ];

    /**
     * Columns update() may write: everything except the `id` key and the
     * lock columns (`lock_run_id`, `lock_heartbeat_at`), which only
     * acquireLock()/heartbeat()/releaseLock() may touch — update() must not
     * be a way around the compare-and-set.
     */
    private const WRITABLE_COLUMNS = [
        'watermark', 'full_sync_completed_at',
        'run_mode', 'run_trigger', 'run_started_at', 'run_phase', 'run_cursor',
        'run_idempotency_key', 'run_requests', 'run_records', 'run_transient_failures',
        'last_run_started_at', 'last_run_finished_at', 'last_success_at', 'last_status',
        'last_error_code', 'last_error_message', 'last_run_requests', 'last_run_records',
        'total_requests', 'credits_remaining', 'credits_remaining_at', 'next_attempt_after',
    ];

    /** Lock is free, or held but its heartbeat is older than :stale (or missing). */
    private const LOCK_AVAILABLE = '(lock_run_id IS NULL OR lock_heartbeat_at IS NULL OR lock_heartbeat_at < :stale)';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WokeometerSyncState::class);
    }

    public function ensureRow(): void
    {
        $this->db()->executeStatement('INSERT OR IGNORE INTO wokeometer_sync_state (id) VALUES (1)');
    }

    /**
     * Every column of the state row. String columns stay strings (or null);
     * all others are cast to int (null stays null).
     *
     * @return array<string, int|string|null>
     */
    public function get(): array
    {
        $db  = $this->db();
        $row = $db->fetchAssociative('SELECT * FROM wokeometer_sync_state WHERE id = 1');
        if ($row === false) {
            // Only a missing row (SchemaTool schema, hand-deleted row) writes.
            $this->ensureRow();
            $row = $db->fetchAssociative('SELECT * FROM wokeometer_sync_state WHERE id = 1');
            if ($row === false) {
                throw new \RuntimeException('wokeometer_sync_state row missing after ensureRow()');
            }
        }

        $out = [];
        foreach ($row as $col => $value) {
            if ($value === null) {
                $out[$col] = null;
            } elseif (in_array($col, self::STRING_COLUMNS, true)) {
                $out[$col] = (string) $value;
            } else {
                $out[$col] = (int) $value;
            }
        }
        return $out;
    }

    /**
     * Boolean form of acquireLock(): true when the lock was taken (fresh or
     * resumed), false when another live run holds it.
     */
    public function tryAcquireLock(string $runId, int $now, int $staleBefore, string $mode, string $trigger, bool $forceFull = false): bool
    {
        return $this->acquireLock($runId, $now, $staleBefore, $mode, $trigger, $forceFull) !== null;
    }

    /**
     * Compare-and-set lock acquisition (see class docblock). `$mode` is used
     * only by a FRESH run; a resumed run keeps its own mode.
     *
     * @return self::ACQUIRED_*|null null when a live run holds the lock
     */
    public function acquireLock(string $runId, int $now, int $staleBefore, string $mode, string $trigger, bool $forceFull = false): ?string
    {
        $this->ensureRow();
        $db    = $this->db();
        $force = $forceFull ? 1 : 0;

        // 1. Resume an unfinished run: a stale crashed run (lock still held)
        //    whatever was requested, or — unless forced — a free interrupted
        //    run with a usable mode (it keeps that mode).
        $resumed = $db->executeStatement(
            'UPDATE wokeometer_sync_state
                SET lock_run_id = :run, lock_heartbeat_at = :now, run_trigger = :trigger
              WHERE id = 1 AND ' . self::LOCK_AVAILABLE . "
                AND run_phase IS NOT NULL AND run_phase <> 'done'
                AND (lock_run_id IS NOT NULL OR (:force = 0 AND run_mode IN ('full', 'incremental')))",
            ['run' => $runId, 'now' => $now, 'trigger' => $trigger, 'stale' => $staleBefore, 'force' => $force],
            ['now' => ParameterType::INTEGER, 'stale' => ParameterType::INTEGER, 'force' => ParameterType::INTEGER],
        );
        if ($resumed === 1) {
            return self::ACQUIRED_RESUMED;
        }

        // 2. Start a fresh run: nothing unfinished, a free interrupted run
        //    superseded by a FORCED full start (atomically, in this UPDATE),
        //    or a free unfinished row without a usable mode (malformed).
        $fresh = $db->executeStatement(
            'UPDATE wokeometer_sync_state
                SET lock_run_id = :run, lock_heartbeat_at = :now, run_mode = :mode, run_trigger = :trigger,
                    run_started_at = :now, run_phase = :phase, run_cursor = NULL, run_idempotency_key = NULL,
                    run_requests = 0, run_records = 0, run_transient_failures = 0, last_run_started_at = :now
              WHERE id = 1 AND ' . self::LOCK_AVAILABLE . "
                AND (run_phase IS NULL OR run_phase = 'done'
                     OR (lock_run_id IS NULL
                         AND (:force = 1 OR run_mode IS NULL OR run_mode NOT IN ('full', 'incremental'))))",
            ['run' => $runId, 'now' => $now, 'mode' => $mode, 'trigger' => $trigger, 'phase' => 'movie', 'stale' => $staleBefore, 'force' => $force],
            ['now' => ParameterType::INTEGER, 'stale' => ParameterType::INTEGER, 'force' => ParameterType::INTEGER],
        );

        return $fresh === 1 ? self::ACQUIRED_FRESH : null;
    }

    /**
     * Refresh the heartbeat of the run holding the lock.
     *
     * @return bool false when $runId no longer holds the lock (taken over)
     */
    public function heartbeat(string $runId, int $now): bool
    {
        return $this->db()->executeStatement(
            'UPDATE wokeometer_sync_state SET lock_heartbeat_at = ? WHERE id = 1 AND lock_run_id = ?',
            [$now, $runId],
            [ParameterType::INTEGER, ParameterType::STRING],
        ) === 1;
    }

    /**
     * Release the lock only if $runId holds it (a taken-over run releasing
     * late must not free its successor's lock). Run state (phase, cursor…)
     * is left untouched so a stopped run stays resumable.
     *
     * @return bool whether the lock was released
     */
    public function releaseLock(string $runId): bool
    {
        return $this->db()->executeStatement(
            'UPDATE wokeometer_sync_state SET lock_run_id = NULL, lock_heartbeat_at = NULL WHERE id = 1 AND lock_run_id = ?',
            [$runId],
        ) === 1;
    }

    /**
     * Write arbitrary state columns. Keys are validated against a column
     * whitelist BEFORE anything is written; values are always bound.
     *
     * With `$ownerRunId` the write only lands while that run holds the lock
     * (`AND lock_run_id = ?`): a run that was taken over writes nothing.
     * Controller / settings writes pass no owner.
     *
     * @param array<string, int|string|bool|null> $fields column => value
     * @return int affected rows — 1, or 0 when `$ownerRunId` no longer holds
     *             the lock (always 0 for an empty `$fields`)
     * @throws \InvalidArgumentException on an unknown column
     */
    public function update(array $fields, ?string $ownerRunId = null): int
    {
        if ($fields === []) {
            return 0;
        }

        $sets   = [];
        $params = [];
        $types  = [];
        foreach ($fields as $col => $value) {
            if (!in_array($col, self::WRITABLE_COLUMNS, true)) {
                throw new \InvalidArgumentException(sprintf('Unknown wokeometer_sync_state column "%s".', $col));
            }
            if (is_bool($value)) {
                $value = $value ? 1 : 0;
            }
            $sets[]   = $col . ' = ?';
            $params[] = $value;
            $types[]  = match (true) {
                $value === null => ParameterType::NULL,
                is_int($value)  => ParameterType::INTEGER,
                default         => ParameterType::STRING,
            };
        }

        $where = 'id = 1';
        if ($ownerRunId !== null) {
            $where   .= ' AND lock_run_id = ?';
            $params[] = $ownerRunId;
            $types[]  = ParameterType::STRING;
        }

        $this->ensureRow();

        return (int) $this->db()->executeStatement(
            'UPDATE wokeometer_sync_state SET ' . implode(', ', $sets) . ' WHERE ' . $where,
            $params,
            $types,
        );
    }

    private function db(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }
}
