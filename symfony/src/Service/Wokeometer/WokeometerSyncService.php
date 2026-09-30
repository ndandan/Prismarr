<?php

namespace App\Service\Wokeometer;

use App\Repository\Media\WokeometerSyncStateRepository;
use App\Repository\Media\WokeometerTitleRepository;
use App\Service\Media\WokeometerClient;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Wokeometer catalog sync orchestration (spec D1–D7, D13, D15). Runs ONLY in
 * the messenger worker, via SyncWokeometerCatalogHandler — this is the one
 * place that calls WokeometerClient::listMedia(), i.e. the only code path
 * that spends credits.
 *
 * A run = two phases (`movie`, then `tv`), each a cursor walk of `GET
 * /media`. It is executed in chunks of ≤ MAX_PAGES_PER_CHUNK pages paced
 * PAGE_PACING_MICROS apart; between chunks the handler re-dispatches itself
 * with a DelayStamp so the single consumer keeps serving SWR refreshes.
 *
 * All durable state lives in the single `wokeometer_sync_state` row:
 *  - Lock (D4): compare-and-set via the repository, which also decides
 *    resume-vs-fresh atomically. Ownership is re-checked (heartbeat()) before
 *    every page, and EVERY run-path state write is owner-checked
 *    (`update($fields, $runId)` → `AND lock_run_id = ?`): a write that lands
 *    on 0 rows means the run was taken over, and it stops without writing
 *    anything else (its successor replays the persisted Idempotency-Key).
 *  - Idempotency-Key (D6): persisted BEFORE each request, reused on retry
 *    (429 / 5xx / transport / crash), rotated after the page commits. A
 *    replay is free within Wokeometer's 24 h idempotency window.
 *  - Watermark (D5): the run's ORIGINAL start (kept across resumes),
 *    committed only when both phases finish. Incremental runs ask for
 *    `updated_since = watermark − OVERLAP_SECONDS`.
 *  - Deletion sweep (D13): only after a completed FULL run, per media type,
 *    and only for a type whose phase returned at least one row (an empty
 *    phase is far more likely an API glitch than an emptied catalog).
 *  - Phase end: only `next_cursor === null` ends a phase (an empty page that
 *    still carries a cursor is followed); a cursor equal to the one sent
 *    trips the loop guard.
 *  - Settings are re-read before every page (settings->refresh() +
 *    client->reset()), so a switch-off or key change lands within one page.
 *
 * Not stops: a 429 below MAX_RATE_LIMIT_RETRIES in a row and a 5xx/transport
 * failure below MAX_TRANSIENT_FAILURES — the chunk returns `continue` with a
 * delay (Retry-After ≤ 15 min / 30 s) and the SAME Idempotency-Key; the lock
 * stays held. Both count in `run_transient_failures`; any OK page resets it.
 *
 * Stop policy. 4xx/5xx/transport responses are UNBILLED, while restarting a
 * run re-bills every page up to its cursor — so almost every stop is
 * resumable. Every stop records `last_status`, `last_error_code/_message`,
 * `last_run_*`, sets `next_attempt_after`, resets `run_transient_failures`
 * (a resumed run gets a fresh budget), releases the lock and logs one
 * warning ("Wokeometer sync stopped": status, code, message, resumable).
 *
 * | trigger                                   | last_status       | backoff | resumable                        | automatic sync          |
 * |-------------------------------------------|-------------------|---------|----------------------------------|-------------------------|
 * | 402 out of credits                        | `out_of_credits`  | 24 h    | yes (new key)                    | resumes after backoff   |
 * | 401 / 403                                 | `auth`/`forbidden`| 24 h    | yes (same key)                   | resumes after backoff   |
 * | 3 consecutive 5xx / transport failures    | `error`           | 1 h     | yes (same key)                   | resumes after backoff   |
 * | 10 consecutive 429 (MAX_RATE_LIMIT_RETRIES)| `error`          | 1 h     | yes (same key)                   | resumes after backoff   |
 * | 409 conflict (key still processing)       | `error`           | 1 h     | yes (same key)                   | resumes after backoff   |
 * | disabled mid-run (switch off / no key)    | `error`           | 1 h     | yes (same key)                   | resumes once re-enabled |
 * | client reports unconfigured               | `error`           | 1 h     | yes (same key)                   | resumes after backoff   |
 * | any Throwable after start()               | `error`           | 6 h     | yes (same key)                   | resumes after backoff   |
 * | invalid (404/…, unreadable 2xx)           | `invalid`         | 7 days  | yes (same key)                   | resumes after backoff   |
 * | 400 on a page requested WITH a cursor     | `invalid`         | 7 days  | yes — phase restarts at its first page (cursor + key cleared) | resumes after backoff |
 * | cursor did not advance (loop guard)       | `halted`          | 24 h    | NO — next run is fresh           | PAUSED until Sync now   |
 * | (internal) unknown `run_phase` in row     | `halted`          | 24 h    | NO — next run is fresh           | PAUSED until Sync now   |
 * | REQUEST_CAP_PER_RUN reached               | `request_cap`     | 24 h    | NO — next run is fresh           | PAUSED until Sync now   |
 *
 * Resumable = `run_phase`, `run_cursor`, `run_idempotency_key`,
 * `run_started_at` kept: the next NON-forced start of any mode (after the
 * backoff, or at once for a manual start) continues the run in its own mode
 * from the cursor and replays the in-flight key. Non-resumable = those
 * columns nulled, AND isDue() stays false while `last_status` is
 * `request_cap` / `halted`: the scheduler never walks back into a runaway.
 * Only a manual Sync now clears the pause — it starts fresh and re-bills
 * from page 1 (up to REQUEST_CAP_PER_RUN). A 402 rotates the key instead of
 * keeping it: the request was never executed, so a new key cannot
 * double-bill and cannot hit a replayed 402. A key save clears the backoff
 * (settings), so an auth-stopped run continues where it stopped.
 *
 * Scheduling. The FIRST full sync (~200 billed requests) is manual-only:
 * isDue() is false while no full sync ever completed, unless an interrupted
 * run (one the admin started) is waiting to resume. Afterwards the scheduler
 * runs an incremental sync every SYNC_INTERVAL_DAYS. `start()` with trigger
 * `manual` ignores `next_attempt_after` and the pause; every other trigger
 * respects both. Any successful start() clears `next_attempt_after`.
 *
 * Non-final: handler tests mock it; sync tests override now()/pause().
 */
class WokeometerSyncService implements ResetInterface
{
    public const MAX_PAGES_PER_CHUNK     = 8;
    public const PAGE_PACING_MICROS      = 1_200_000;
    public const REQUEST_CAP_PER_RUN     = 600;
    public const OVERLAP_SECONDS         = 172_800;
    public const STALE_LOCK_SECONDS      = 1_800;
    public const AUTH_BACKOFF_SECONDS    = 86_400;
    public const CREDITS_BACKOFF_SECONDS = 86_400;
    public const ERROR_BACKOFF_SECONDS   = 86_400;
    public const INVALID_BACKOFF_SECONDS = 604_800;
    /** Resumable `error` stops on unbilled outcomes (transient trip, 429 storm, 409, disabled/unconfigured). */
    public const TRANSIENT_BACKOFF_SECONDS = 3_600;
    /**
     * Resumable `error` stop after an internal Throwable. Longer than the
     * transient backoff: a fault that repeats identically re-bills its page
     * once the 24 h replay window lapses — 6 h bounds that to <= 4 pages/day.
     */
    public const INTERNAL_ERROR_BACKOFF_SECONDS = 21_600;
    public const MAX_TRANSIENT_FAILURES  = 3;
    public const CHUNK_DELAY_SECONDS     = 2;
    public const TRANSIENT_RETRY_SECONDS = 30;

    /** 429 without a usable Retry-After. */
    public const DEFAULT_RATE_LIMIT_SECONDS = 60;
    /** Cap on a 429 wait: well under STALE_LOCK_SECONDS so the held lock never looks crashed. */
    public const MAX_RATE_LIMIT_WAIT_SECONDS = 900;
    /** Consecutive 429s (counted in `run_transient_failures`) before a resumable `error` stop. */
    public const MAX_RATE_LIMIT_RETRIES = 10;

    public const STATUS_RUNNING        = 'running';
    public const STATUS_OK             = 'ok';
    public const STATUS_ERROR          = 'error';
    public const STATUS_REQUEST_CAP    = 'request_cap';
    public const STATUS_AUTH           = 'auth';
    public const STATUS_FORBIDDEN      = 'forbidden';
    public const STATUS_OUT_OF_CREDITS = 'out_of_credits';
    public const STATUS_INVALID        = 'invalid';
    /** Non-resumable safety stop (loop guard / unknown phase): automatic sync paused. */
    public const STATUS_HALTED         = 'halted';

    /** `last_status` values that pause automatic scheduling until a manual start. */
    public const PAUSING_STATUSES = [self::STATUS_REQUEST_CAP, self::STATUS_HALTED];

    public const MODE_FULL        = 'full';
    public const MODE_INCREMENTAL = 'incremental';

    /** Phase order: movie → tv → done. */
    private const NEXT_PHASE = ['movie' => 'tv', 'tv' => 'done'];

    private const MESSAGE_MAX = 255;

    private ?string $lastStartReason = null;

    public function __construct(
        private readonly WokeometerSettings $settings,
        private readonly WokeometerClient $client,
        private readonly WokeometerTitleRepository $titles,
        private readonly WokeometerSyncStateRepository $state,
        private readonly LoggerInterface $logger,
    ) {}

    public function reset(): void
    {
        $this->lastStartReason = null;
    }

    /**
     * Why the last start() returned null: `disabled` | `backoff` | `locked` |
     * `not_due` (a non-manual start while paused, or before the first full
     * sync with nothing to resume). Null after a successful start.
     */
    public function lastStartReason(): ?string
    {
        return $this->lastStartReason;
    }

    /**
     * Should the hourly tick queue a scheduled start? Enabled AND auto-sync
     * on AND not paused by a runaway stop (`last_status` request_cap /
     * halted) AND no active backoff AND the lock free-or-stale, AND one of:
     * an unfinished run to take over (stale lock) or resume (free, e.g. after
     * a 402 backoff), or — only once a first full sync has completed — the
     * last success ≥ 30 days ago. Never before the first full sync: that one
     * is started by the admin (Sync now).
     */
    public function isDue(int $now): bool
    {
        if (!$this->settings->isEnabled() || !$this->settings->isAutoSyncEnabled()) {
            return false;
        }

        $s = $this->state->get();
        if (self::isPaused($s)) {
            return false;
        }
        $nextAttempt = self::intOrNull($s['next_attempt_after']);
        if ($nextAttempt !== null && $nextAttempt > $now) {
            return false;
        }

        if ($s['lock_run_id'] !== null) {
            // Held by a live run → not due. Held but stale → a crashed
            // unfinished run is due (takeover resumes it); a stale lock on a
            // FINISHED run (crash between the final write and the release)
            // falls through to the normal 30-day check instead of forcing
            // an extra billed run.
            $heartbeat = self::intOrNull($s['lock_heartbeat_at']);
            if ($heartbeat !== null && $heartbeat >= $now - self::STALE_LOCK_SECONDS) {
                return false;
            }
        }

        if (self::isUnfinished($s['run_phase'])) {
            return true;
        }

        $fullDone = self::intOrNull($s['full_sync_completed_at']);
        if ($fullDone === null) {
            return false; // the initial full sync is manual-only
        }
        $lastSuccess = self::intOrNull($s['last_success_at']) ?? $fullDone;

        return $lastSuccess + WokeometerSettings::SYNC_INTERVAL_DAYS * 86_400 <= $now;
    }

    /**
     * Acquire the run lock and return the run id, or null (see
     * lastStartReason()). Fresh-run mode: `full` when forced or no full sync
     * ever completed, else `incremental`.
     *
     * Whether the run is fresh or resumed is decided atomically by the
     * repository's compare-and-set (never pre-clear run_phase here): a
     * NON-forced start resumes a free interrupted run in that run's own mode
     * (phase, cursor, idempotency key, counters and ORIGINAL run_started_at
     * kept), while a FORCED full start supersedes it with a fresh run. One
     * case is resolved here: a forced start that took over a STALE-held
     * unfinished run (reported `resumed`) converts it in place (we hold the
     * lock) into a fresh full run — "Full resync" means start over.
     *
     * A `manual` trigger ignores `next_attempt_after` and the runaway pause
     * (the admin asked explicitly); any other trigger respects both and is
     * refused (`not_due`) before the first full sync unless an interrupted
     * run exists. Every successful start clears `next_attempt_after`. If a
     * state write after the lock was taken throws, the lock is released
     * before the exception is rethrown.
     */
    public function start(string $trigger, bool $forceFull, ?int $now = null): ?string
    {
        $now ??= $this->now();
        $this->lastStartReason = null;

        if (!$this->settings->isEnabled()) {
            return $this->refuse('disabled');
        }

        $s = $this->state->get();
        if ($trigger !== 'manual') {
            $nextAttempt = self::intOrNull($s['next_attempt_after']);
            if ($nextAttempt !== null && $nextAttempt > $now) {
                return $this->refuse('backoff');
            }
            $firstSyncPending = self::intOrNull($s['full_sync_completed_at']) === null && !self::isUnfinished($s['run_phase']);
            if (self::isPaused($s) || $firstSyncPending) {
                return $this->refuse('not_due');
            }
        }

        $mode     = $this->modeFor($s, $forceFull);
        $runId    = $this->newRunId();
        $acquired = $this->state->acquireLock($runId, $now, $now - self::STALE_LOCK_SECONDS, $mode, $trigger, $forceFull);
        if ($acquired === null) {
            return $this->refuse('locked');
        }

        try {
            if ($forceFull && $acquired === WokeometerSyncStateRepository::ACQUIRED_RESUMED) {
                $converted = $this->write($runId, [
                    'run_mode'               => self::MODE_FULL,
                    'run_phase'              => 'movie',
                    'run_cursor'             => null,
                    'run_idempotency_key'    => null,
                    'run_requests'           => 0,
                    'run_records'            => 0,
                    'run_transient_failures' => 0,
                    'run_started_at'         => $now,
                    'run_trigger'            => $trigger,
                    'last_run_started_at'    => $now,
                ]);
                if (!$converted) {
                    return $this->refuse('locked');
                }
                $acquired = WokeometerSyncStateRepository::ACQUIRED_FRESH;
            }

            $fields = $acquired === WokeometerSyncStateRepository::ACQUIRED_FRESH
                ? ['last_status' => self::STATUS_RUNNING, 'last_error_code' => null, 'last_error_message' => null]
                : ['last_status' => self::STATUS_RUNNING];
            $fields['next_attempt_after'] = null;
            if (!$this->write($runId, $fields)) {
                return $this->refuse('locked');
            }
        } catch (\Throwable $e) {
            try {
                $this->state->releaseLock($runId);
            } catch (\Throwable) {
                // DB unusable: the lock goes stale and is taken over later.
            }
            throw $e;
        }

        return $runId;
    }

    /**
     * Process up to MAX_PAGES_PER_CHUNK pages of the run. Never throws: any
     * Throwable becomes a RESUMABLE `error` stop (sanitized class name as the
     * message, INTERNAL_ERROR_BACKOFF_SECONDS = 6 h, key kept → the in-flight
     * page replays for free within the 24 h window) with the lock released.
     * A run whose `run_phase` is already `done` (e.g. a duplicate
     * continuation) only releases the lock and reports `done`.
     */
    public function runChunk(string $runId): WokeometerChunkResult
    {
        try {
            return $this->processChunk($runId);
        } catch (\Throwable $e) {
            $label = self::throwableLabel($e);
            try {
                return $this->stop($runId, self::STATUS_ERROR, null, $label, self::INTERNAL_ERROR_BACKOFF_SECONDS, true);
            } catch (\Throwable) {
                $this->logger->warning('Wokeometer sync chunk aborted', ['path' => '/media', 'code' => 0, 'message' => $label]);
                try {
                    $this->state->releaseLock($runId);
                } catch (\Throwable) {
                    // DB unusable: the lock goes stale and is taken over later.
                }
                return new WokeometerChunkResult(WokeometerChunkResult::STOPPED, 0, self::STATUS_ERROR);
            }
        }
    }

    /**
     * Status for the settings card (spec §6). All timestamps are epoch ints
     * or null. `$matchedTitles` (`callable(): ?int`) computes the matched
     * library count from already-cached library rows; any failure → null.
     *
     * @param (callable(): ?int)|null $matchedTitles
     * @return array{
     *     enabled: bool, configured: bool, autoSync: bool, running: bool,
     *     runMode: ?string, runPhase: ?string, runRequests: int, runRecords: int,
     *     lastStatus: ?string, lastErrorMessage: ?string, lastSuccessAt: ?int,
     *     lastRunStartedAt: ?int, lastRunFinishedAt: ?int, lastRunRequests: ?int,
     *     lastRunRecords: ?int, totalRequests: int, creditsRemaining: ?int,
     *     creditsRemainingAt: ?int, nextAttemptAfter: ?int, fullSyncCompletedAt: ?int,
     *     watermark: ?int, nextDueAt: ?int,
     *     cached: array{total: int, movies: int, series: int, seasons: int, withTmdb: int},
     *     matchedTitles: ?int
     * }
     */
    public function statusSummary(?callable $matchedTitles = null): array
    {
        $now = $this->now();
        $s   = $this->state->get();

        $heartbeat   = self::intOrNull($s['lock_heartbeat_at']);
        $lastSuccess = self::intOrNull($s['last_success_at']);

        $matched = null;
        if ($matchedTitles !== null) {
            try {
                $value   = $matchedTitles();
                $matched = is_int($value) ? $value : null;
            } catch (\Throwable) {
                $matched = null;
            }
        }

        return [
            'enabled'             => $this->settings->isEnabled(),
            'configured'          => $this->settings->apiKey() !== null,
            'autoSync'            => $this->settings->isAutoSyncEnabled(),
            'running'             => $s['lock_run_id'] !== null && $heartbeat !== null && $heartbeat >= $now - self::STALE_LOCK_SECONDS,
            'runMode'             => self::stringOrNull($s['run_mode']),
            'runPhase'            => self::stringOrNull($s['run_phase']),
            'runRequests'         => self::intOrNull($s['run_requests']) ?? 0,
            'runRecords'          => self::intOrNull($s['run_records']) ?? 0,
            'lastStatus'          => self::stringOrNull($s['last_status']),
            'lastErrorMessage'    => self::stringOrNull($s['last_error_message']),
            'lastSuccessAt'       => $lastSuccess,
            'lastRunStartedAt'    => self::intOrNull($s['last_run_started_at']),
            'lastRunFinishedAt'   => self::intOrNull($s['last_run_finished_at']),
            'lastRunRequests'     => self::intOrNull($s['last_run_requests']),
            'lastRunRecords'      => self::intOrNull($s['last_run_records']),
            'totalRequests'       => self::intOrNull($s['total_requests']) ?? 0,
            'creditsRemaining'    => self::intOrNull($s['credits_remaining']),
            'creditsRemainingAt'  => self::intOrNull($s['credits_remaining_at']),
            'nextAttemptAfter'    => self::intOrNull($s['next_attempt_after']),
            'fullSyncCompletedAt' => self::intOrNull($s['full_sync_completed_at']),
            'watermark'           => self::intOrNull($s['watermark']),
            'nextDueAt'           => $lastSuccess !== null && !self::isPaused($s) ? $lastSuccess + WokeometerSettings::SYNC_INTERVAL_DAYS * 86_400 : null,
            'cached'              => $this->titles->counts(),
            'matchedTitles'       => $matched,
        ];
    }

    /**
     * A log/state-safe label for a Throwable: its class name only (never
     * getMessage(), which may carry provider text), anonymous-class suffix
     * stripped, restricted to class-name characters, ≤ 255 chars.
     */
    public static function throwableLabel(\Throwable $e): string
    {
        $class = $e::class;
        $nul   = strpos($class, "\0");
        if ($nul !== false) {
            $class = substr($class, 0, $nul);
        }
        $class = (string) preg_replace('/[^A-Za-z0-9_\\\\@]/', '', $class);

        return substr($class !== '' ? $class : 'Throwable', 0, self::MESSAGE_MAX);
    }

    protected function now(): int
    {
        return time();
    }

    protected function pause(int $micros): void
    {
        usleep($micros);
    }

    protected function newRunId(): string
    {
        return self::uuid4();
    }

    protected function newIdempotencyKey(): string
    {
        return self::uuid4();
    }

    private function processChunk(string $runId): WokeometerChunkResult
    {
        if ($this->state->get()['lock_run_id'] !== $runId) {
            return self::notOwner();
        }

        $dropped = 0;
        try {
            for ($page = 0; $page < self::MAX_PAGES_PER_CHUNK; $page++) {
                if ($page > 0) {
                    $this->pause(self::PAGE_PACING_MICROS); // between pages, never after the last
                }

                // Long-lived worker: pick up a switch-off / key change saved mid-run.
                $this->settings->refresh();
                $this->client->reset();

                if (!$this->state->heartbeat($runId, $this->now())) {
                    return self::notOwner();
                }
                $s = $this->state->get();

                $phase = self::stringOrNull($s['run_phase']);
                if ($phase === 'done') {
                    // Already finished (duplicate continuation): nothing to do but let go.
                    $this->state->releaseLock($runId);
                    return new WokeometerChunkResult(WokeometerChunkResult::DONE);
                }
                if (!$this->settings->isEnabled()) {
                    return $this->stop($runId, self::STATUS_ERROR, null, 'disabled mid-run', self::TRANSIENT_BACKOFF_SECONDS, true);
                }
                if ($phase !== 'movie' && $phase !== 'tv') {
                    return $this->stop($runId, self::STATUS_HALTED, null, 'invalid run phase', self::ERROR_BACKOFF_SECONDS, false);
                }
                $runRequests = self::intOrNull($s['run_requests']) ?? 0;
                if ($runRequests >= self::REQUEST_CAP_PER_RUN) {
                    return $this->stop($runId, self::STATUS_REQUEST_CAP, null, 'request cap reached', self::ERROR_BACKOFF_SECONDS, false);
                }

                $cursor = self::stringOrNull($s['run_cursor']);
                $key    = self::stringOrNull($s['run_idempotency_key']);
                if ($key === null) {
                    // Persist BEFORE the request: a crash or retry replays this key for free.
                    $key = $this->newIdempotencyKey();
                    if (!$this->write($runId, ['run_idempotency_key' => $key])) {
                        return self::notOwner();
                    }
                }

                $watermark    = self::intOrNull($s['watermark']);
                $updatedSince = ($s['run_mode'] === self::MODE_INCREMENTAL && $watermark !== null)
                    ? max(0, $watermark - self::OVERLAP_SECONDS)
                    : null;

                $result = $this->client->listMedia($phase, $cursor, $updatedSince, $key);

                $now = $this->now();
                if (!$this->state->heartbeat($runId, $now)) {
                    // Taken over mid-request: write nothing. The successor
                    // replays the persisted key, so this page is not re-billed.
                    return self::notOwner();
                }

                // Bookkeeping common to every outcome. A 2xx was billed
                // (unless replayed) even when its body turns out unusable.
                $fields = [];
                $billed = $result->isOk() || ($result->httpCode >= 200 && $result->httpCode < 300);
                if ($billed) {
                    $runRequests++;
                    $fields['run_requests']   = $runRequests;
                    $fields['total_requests'] = (self::intOrNull($s['total_requests']) ?? 0) + ($result->replayed ? 0 : 1);
                }
                if ($result->creditsRemaining !== null) {
                    $fields['credits_remaining']    = $result->creditsRemaining;
                    $fields['credits_remaining_at'] = $now;
                }

                switch ($result->outcome) {
                    case WokeometerPageResult::OK:
                        $dropped += $result->droppedRows;
                        $startedAt = self::intOrNull($s['run_started_at']) ?? $now;
                        $upserted  = $this->titles->upsertRows($result->rows, $startedAt, $now);

                        $fields['run_records']            = (self::intOrNull($s['run_records']) ?? 0) + $upserted;
                        $fields['run_idempotency_key']    = null; // page committed → rotate
                        $fields['run_transient_failures'] = 0;

                        // Only a null cursor ends the phase: an empty page
                        // that still carries a cursor is followed.
                        if ($result->nextCursor === null) {
                            $next = self::NEXT_PHASE[$phase];
                            if ($next === 'done') {
                                return $this->finish($runId, $s, $fields);
                            }
                            $fields['run_phase']  = $next;
                            $fields['run_cursor'] = null;
                        } elseif ($result->nextCursor === $cursor) {
                            return $this->stop($runId, self::STATUS_HALTED, $result->httpCode, 'cursor did not advance', self::ERROR_BACKOFF_SECONDS, false, $fields);
                        } else {
                            $fields['run_cursor'] = $result->nextCursor;
                        }

                        if (!$this->write($runId, $fields)) {
                            return self::notOwner();
                        }
                        if ($runRequests >= self::REQUEST_CAP_PER_RUN) {
                            return $this->stop($runId, self::STATUS_REQUEST_CAP, null, 'request cap reached', self::ERROR_BACKOFF_SECONDS, false);
                        }
                        break;

                    case WokeometerPageResult::RATE_LIMITED:
                        // Unbilled, but a 429 storm must not keep the run alive forever.
                        $failures = (self::intOrNull($s['run_transient_failures']) ?? 0) + 1;
                        if ($failures >= self::MAX_RATE_LIMIT_RETRIES) {
                            return $this->stop($runId, self::STATUS_ERROR, $result->httpCode, 'rate limited too many times in a row', self::TRANSIENT_BACKOFF_SECONDS, true, $fields);
                        }
                        $fields['run_transient_failures'] = $failures; // key kept → free replay
                        if (!$this->write($runId, $fields)) {
                            return self::notOwner();
                        }
                        $wait = $result->retryAfter ?? self::DEFAULT_RATE_LIMIT_SECONDS;
                        return new WokeometerChunkResult(
                            WokeometerChunkResult::CONTINUE,
                            max(1, min(self::MAX_RATE_LIMIT_WAIT_SECONDS, $wait)),
                        );

                    case WokeometerPageResult::TRANSIENT:
                    case WokeometerPageResult::TRANSPORT:
                        $failures = (self::intOrNull($s['run_transient_failures']) ?? 0) + 1;
                        if ($failures >= self::MAX_TRANSIENT_FAILURES) {
                            return $this->stop($runId, self::STATUS_ERROR, $result->httpCode, $result->message, self::TRANSIENT_BACKOFF_SECONDS, true, $fields);
                        }
                        $fields['run_transient_failures'] = $failures; // key kept → free replay
                        if (!$this->write($runId, $fields)) {
                            return self::notOwner();
                        }
                        return new WokeometerChunkResult(WokeometerChunkResult::CONTINUE, self::TRANSIENT_RETRY_SECONDS);

                    case WokeometerPageResult::AUTH:
                        return $this->stop($runId, self::STATUS_AUTH, $result->httpCode, $result->message, self::AUTH_BACKOFF_SECONDS, true, $fields);

                    case WokeometerPageResult::FORBIDDEN:
                        return $this->stop($runId, self::STATUS_FORBIDDEN, $result->httpCode, $result->message, self::AUTH_BACKOFF_SECONDS, true, $fields);

                    case WokeometerPageResult::OUT_OF_CREDITS:
                        // Resumable: phase + cursor kept. The key is rotated —
                        // a 402 was never executed, so a new key on resume
                        // cannot double-bill, and it cannot hit a replayed 402.
                        $fields['run_idempotency_key'] = null;
                        return $this->stop($runId, self::STATUS_OUT_OF_CREDITS, $result->httpCode, $result->message, self::CREDITS_BACKOFF_SECONDS, true, $fields);

                    case WokeometerPageResult::INVALID:
                        if ($cursor !== null && $result->httpCode === 400) {
                            // The cursor is the likely culprit (expired / rejected):
                            // resuming with it would fail forever, so the phase
                            // restarts at its first page when the run resumes.
                            $fields['run_cursor']          = null;
                            $fields['run_idempotency_key'] = null;
                        }
                        return $this->stop($runId, self::STATUS_INVALID, $result->httpCode, $result->message, self::INVALID_BACKOFF_SECONDS, true, $fields);

                    default: // conflict (key still processing → same key), unconfigured, anything unknown
                        return $this->stop($runId, self::STATUS_ERROR, $result->httpCode, $result->message ?? $result->outcome, self::TRANSIENT_BACKOFF_SECONDS, true, $fields);
                }
            }

            return new WokeometerChunkResult(WokeometerChunkResult::CONTINUE, self::CHUNK_DELAY_SECONDS);
        } finally {
            if ($dropped > 0) {
                $this->logger->warning('Wokeometer sync dropped unreadable rows', [
                    'path'    => '/media',
                    'code'    => 0,
                    'message' => sprintf('%d row(s) failed normalization in this chunk', $dropped),
                ]);
            }
        }
    }

    /**
     * Both phases finished: commit the watermark (= the run's ORIGINAL start),
     * sweep unseen rows after a FULL run (per media type, only for a type
     * this run returned rows of), copy the run stats, release the lock.
     *
     * @param array<string, int|string|null> $s      state row read before the last page
     * @param array<string, int|string|null> $fields pending writes for the last page
     */
    private function finish(string $runId, array $s, array $fields): WokeometerChunkResult
    {
        $now = $this->now();
        if (!$this->state->heartbeat($runId, $now)) {
            return self::notOwner();
        }

        $startedAt = self::intOrNull($s['run_started_at']) ?? $now;
        $full      = $s['run_mode'] === self::MODE_FULL;
        $records   = self::intOrNull($fields['run_records'] ?? $s['run_records']) ?? 0;

        // A phase that returned nothing is far more likely an API glitch
        // than an emptied catalog: never let it wipe that type's mirror.
        if ($full) {
            foreach (array_keys(self::NEXT_PHASE) as $type) {
                if ($this->titles->countSeenSince($type, $startedAt) > 0) {
                    $this->titles->sweepUnseen($startedAt, $type);
                }
            }
        }

        $fields = array_merge($fields, [
            'watermark'            => $startedAt,
            'last_success_at'      => $now,
            'last_status'          => self::STATUS_OK,
            'last_error_code'      => null,
            'last_error_message'   => null,
            'last_run_finished_at' => $now,
            'last_run_requests'    => self::intOrNull($fields['run_requests'] ?? $s['run_requests']) ?? 0,
            'last_run_records'     => $records,
            'run_phase'            => 'done',
            'run_cursor'           => null,
            'run_idempotency_key'  => null,
            'next_attempt_after'   => null,
        ]);
        if ($full) {
            $fields['full_sync_completed_at'] = $now;
        }

        if (!$this->write($runId, $fields)) {
            return self::notOwner();
        }
        $this->state->releaseLock($runId);

        return new WokeometerChunkResult(WokeometerChunkResult::DONE);
    }

    /**
     * End the run without finishing: record the status, copy the run stats,
     * set the backoff, reset the transient budget, release the lock and log
     * one warning. `$resumable` keeps phase/cursor/key/started_at (see the
     * class-level stop table); only the halting stops (loop guard, unknown
     * phase) and the request cap clear them so the next start is fresh. A run
     * that lost the lock writes nothing, releases nothing and logs nothing.
     *
     * @param array<string, int|string|null> $fields pending writes from the page
     */
    private function stop(
        string $runId,
        string $status,
        ?int $code,
        ?string $message,
        int $backoffSeconds,
        bool $resumable,
        array $fields = [],
    ): WokeometerChunkResult {
        $now = $this->now();
        if (!$this->state->heartbeat($runId, $now)) {
            return self::notOwner();
        }
        $s = $this->state->get();

        $fields = array_merge($fields, [
            'last_status'          => $status,
            'last_error_code'      => $code,
            'last_error_message'   => $message !== null ? mb_substr($message, 0, self::MESSAGE_MAX) : null,
            'last_run_finished_at' => $now,
            'last_run_requests'    => self::intOrNull($fields['run_requests'] ?? $s['run_requests']) ?? 0,
            'last_run_records'     => self::intOrNull($fields['run_records'] ?? $s['run_records']) ?? 0,
            'next_attempt_after'   => $now + $backoffSeconds,
            // A resumed run gets a fresh transient budget.
            'run_transient_failures' => 0,
        ]);
        if (!$resumable) {
            $fields['run_phase']           = null;
            $fields['run_cursor']          = null;
            $fields['run_idempotency_key'] = null;
        }

        if (!$this->write($runId, $fields)) {
            return self::notOwner();
        }
        $this->state->releaseLock($runId);

        // Never the key or a URL: status, HTTP code (or null) and the
        // already-sanitized message only.
        $this->logger->warning('Wokeometer sync stopped', [
            'status'    => $status,
            'code'      => $code,
            'message'   => $fields['last_error_message'],
            'resumable' => $resumable,
        ]);

        return new WokeometerChunkResult(WokeometerChunkResult::STOPPED, 0, $status);
    }

    /**
     * Mode for a FRESH run (a resumed run keeps its own — decided in the CAS).
     *
     * @param array<string, int|string|null> $s
     */
    private function modeFor(array $s, bool $forceFull): string
    {
        if ($forceFull) {
            return self::MODE_FULL;
        }

        return self::intOrNull($s['full_sync_completed_at']) === null ? self::MODE_FULL : self::MODE_INCREMENTAL;
    }

    /**
     * Owner-checked state write for the run path: false when `$runId` no
     * longer holds the lock (nothing was written). An empty set is a no-op.
     *
     * @param array<string, int|string|null> $fields
     */
    private function write(string $runId, array $fields): bool
    {
        return $fields === [] || $this->state->update($fields, $runId) === 1;
    }

    private function refuse(string $reason): null
    {
        $this->lastStartReason = $reason;
        return null;
    }

    private static function notOwner(): WokeometerChunkResult
    {
        return new WokeometerChunkResult(WokeometerChunkResult::STOPPED, 0, 'not_owner');
    }

    /**
     * Automatic scheduling paused by a runaway stop (request cap / halted).
     *
     * @param array<string, int|string|null> $s
     */
    private static function isPaused(array $s): bool
    {
        return in_array($s['last_status'], self::PAUSING_STATUSES, true);
    }

    private static function isUnfinished(mixed $phase): bool
    {
        return is_string($phase) && $phase !== 'done';
    }

    private static function intOrNull(mixed $v): ?int
    {
        return is_int($v) ? $v : null;
    }

    private static function stringOrNull(mixed $v): ?string
    {
        return is_string($v) ? $v : null;
    }

    /** RFC 4122 version-4 UUID from random_bytes(). */
    private static function uuid4(): string
    {
        $b    = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
