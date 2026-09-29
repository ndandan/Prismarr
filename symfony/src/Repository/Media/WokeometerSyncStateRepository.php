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
 *    (lock still held) — resumed whatever `$mode` was requested — or (b) a
 *    free, interrupted run (stopped on out_of_credits / rate_limited /
 *    transient with its cursor kept) whose `run_mode` equals `$mode`. Only
 *    `lock_run_id`, `lock_heartbeat_at` and `run_trigger` are replaced;
 *    `run_mode`, `run_phase`, `run_cursor`, `run_idempotency_key`,
 *    `run_started_at`, `run_requests`, `run_records` and
 *    `run_transient_failures` are kept so the run continues where it stopped
 *    (with the same idempotency key → free replay of the in-flight page).
 *  - 'fresh' — no unfinished run (`run_phase` null or 'done'), OR a free,
 *    interrupted run of a DIFFERENT mode (e.g. a forced full resync
 *    supersedes an interrupted incremental one, atomically in the same
 *    UPDATE): a new run is initialised with the given mode/trigger,
 *    `run_started_at = last_run_started_at = $now`, phase 'movie',
 *    cursor/idempotency key cleared, run counters zeroed.
 *
 * Each branch is a single conditional UPDATE, so two concurrent acquirers
 * can never both win: whichever UPDATE lands first changes `lock_run_id`,
 * and the other's WHERE no longer matches.
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
    public function tryAcquireLock(string $runId, int $now, int $staleBefore, string $mode, string $trigger): bool
    {
        return $this->acquireLock($runId, $now, $staleBefore, $mode, $trigger) !== null;
    }

    /**
     * Compare-and-set lock acquisition (see class docblock).
     *
     * @return self::ACQUIRED_*|null null when a live run holds the lock
     */
    public function acquireLock(string $runId, int $now, int $staleBefore, string $mode, string $trigger): ?string
    {
        $this->ensureRow();
        $db = $this->db();

        // 1. Resume an unfinished run: a stale crashed run (lock still held)
        //    regardless of mode, or a free interrupted run of the same mode.
        $resumed = $db->executeStatement(
            'UPDATE wokeometer_sync_state
                SET lock_run_id = :run, lock_heartbeat_at = :now, run_trigger = :trigger
              WHERE id = 1 AND ' . self::LOCK_AVAILABLE . "
                AND run_phase IS NOT NULL AND run_phase <> 'done'
                AND (lock_run_id IS NOT NULL OR run_mode = :mode)",
            ['run' => $runId, 'now' => $now, 'trigger' => $trigger, 'stale' => $staleBefore, 'mode' => $mode],
            ['now' => ParameterType::INTEGER, 'stale' => ParameterType::INTEGER],
        );
        if ($resumed === 1) {
            return self::ACQUIRED_RESUMED;
        }

        // 2. Start a fresh run: nothing unfinished, or a free interrupted run
        //    of a different mode (superseded atomically by this UPDATE).
        //    `run_mode IS NULL` keeps a (malformed) unfinished row with no
        //    mode from wedging both branches.
        $fresh = $db->executeStatement(
            'UPDATE wokeometer_sync_state
                SET lock_run_id = :run, lock_heartbeat_at = :now, run_mode = :mode, run_trigger = :trigger,
                    run_started_at = :now, run_phase = :phase, run_cursor = NULL, run_idempotency_key = NULL,
                    run_requests = 0, run_records = 0, run_transient_failures = 0, last_run_started_at = :now
              WHERE id = 1 AND ' . self::LOCK_AVAILABLE . "
                AND (run_phase IS NULL OR run_phase = 'done'
                     OR (lock_run_id IS NULL AND (run_mode IS NULL OR run_mode <> :mode)))",
            ['run' => $runId, 'now' => $now, 'mode' => $mode, 'trigger' => $trigger, 'phase' => 'movie', 'stale' => $staleBefore],
            ['now' => ParameterType::INTEGER, 'stale' => ParameterType::INTEGER],
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
     * @param array<string, int|string|bool|null> $fields column => value
     * @throws \InvalidArgumentException on an unknown column
     */
    public function update(array $fields): void
    {
        if ($fields === []) {
            return;
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

        $this->ensureRow();
        $this->db()->executeStatement(
            'UPDATE wokeometer_sync_state SET ' . implode(', ', $sets) . ' WHERE id = 1',
            $params,
            $types,
        );
    }

    private function db(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }
}
