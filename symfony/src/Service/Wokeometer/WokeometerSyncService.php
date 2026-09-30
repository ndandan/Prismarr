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
 *  - Lock (D4): compare-and-set via the repository; ownership is re-checked
 *    (heartbeat() === true) before every write, and a run that lost the lock
 *    stops without writing anything (its successor replays the persisted
 *    Idempotency-Key for free).
 *  - Idempotency-Key (D6): persisted BEFORE each request, reused on retry
 *    (429 / 5xx / transport / crash), rotated after the page commits.
 *  - Watermark (D5): the run's ORIGINAL start (kept across resumes),
 *    committed only when both phases finish. Incremental runs ask for
 *    `updated_since = watermark − OVERLAP_SECONDS`.
 *  - Deletion sweep (D13): only after a completed FULL run.
 *
 * Not stops: 429 and a 5xx/transport failure below MAX_TRANSIENT_FAILURES —
 * the chunk returns `continue` with a delay (Retry-After ≤ 15 min / 30 s) and
 * the SAME Idempotency-Key; the lock stays held.
 *
 * Stop policy. 4xx/5xx/transport responses are UNBILLED, while restarting a
 * run re-bills every page up to its cursor — so almost every stop is
 * resumable. Every stop records `last_status`, `last_error_code/_message`,
 * `last_run_*`, sets `next_attempt_after`, resets `run_transient_failures`
 * (a resumed run gets a fresh budget), releases the lock and logs one
 * warning ("Wokeometer sync stopped": status, code, message, resumable).
 *
 * | trigger                                | last_status      | backoff | resumable        |
 * |----------------------------------------|------------------|---------|------------------|
 * | 402 out of credits                     | `out_of_credits` | 24 h    | yes (new key)    |
 * | 401 / 403                              | `auth`/`forbidden`| 24 h   | yes (same key)   |
 * | 3 consecutive 5xx / transport failures | `error`          | 1 h     | yes (same key)   |
 * | 409 conflict (key still processing)    | `error`          | 1 h     | yes (same key)   |
 * | unconfigured mid-run (disabled / no key)| `error`         | 1 h     | yes (same key)   |
 * | any Throwable after start()            | `error`          | 6 h     | yes (same key)   |
 * | invalid (400/404/…, unreadable 2xx)    | `invalid`        | 7 days  | yes (same key)   |
 * | cursor did not advance (loop guard)    | `error`          | 24 h    | NO — next is fresh |
 * | REQUEST_CAP_PER_RUN reached            | `request_cap`    | 24 h    | NO — next is fresh |
 * | (internal) unknown `run_phase` in row  | `error`          | 24 h    | NO — next is fresh |
 *
 * Resumable = `run_phase`, `run_cursor`, `run_idempotency_key`,
 * `run_started_at` kept: the next start of the same mode (after the backoff,
 * or at once for a manual start) continues from the cursor and replays the
 * in-flight key. Non-resumable = those columns nulled, so no tick can walk
 * into the same runaway again. A 402 rotates the key instead of keeping it:
 * the request was never executed, so a new key cannot double-bill and cannot
 * hit a replayed 402. A key save clears the backoff (settings), so an
 * auth-stopped run continues where it stopped with the new key.
 *
 * `start()` with trigger `manual` ignores `next_attempt_after`; scheduled
 * starts and isDue() respect it.
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
    /** Resumable `error` stops on unbilled outcomes (transient trip, 409, unconfigured). */
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

    public const STATUS_RUNNING        = 'running';
    public const STATUS_OK             = 'ok';
    public const STATUS_ERROR          = 'error';
    public const STATUS_REQUEST_CAP    = 'request_cap';
    public const STATUS_AUTH           = 'auth';
    public const STATUS_FORBIDDEN      = 'forbidden';
    public const STATUS_OUT_OF_CREDITS = 'out_of_credits';
    public const STATUS_INVALID        = 'invalid';

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

    /** Why the last start() returned null: `disabled` | `backoff` | `locked` (null after a successful start). */
    public function lastStartReason(): ?string
    {
        return $this->lastStartReason;
    }

    /**
     * Should the hourly tick queue a scheduled start? Enabled AND auto-sync
     * on AND no active backoff AND the lock free-or-stale, AND one of: an
     * unfinished run to take over (stale lock) or resume (free, e.g. after a
     * 402 backoff), no completed full sync yet, or the last success ≥ 30
     * days ago.
     */
    public function isDue(int $now): bool
    {
        if (!$this->settings->isEnabled() || !$this->settings->isAutoSyncEnabled()) {
            return false;
        }

        $s = $this->state->get();
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

        $fullDone    = self::intOrNull($s['full_sync_completed_at']);
        $lastSuccess = self::intOrNull($s['last_success_at']);
        if ($fullDone === null || $lastSuccess === null) {
            return true;
        }

        return $lastSuccess + WokeometerSettings::SYNC_INTERVAL_DAYS * 86_400 <= $now;
    }

    /**
     * Acquire the run lock and return the run id, or null (see
     * lastStartReason()). Mode: `full` when forced or no full sync ever
     * completed, else `incremental` — except that a FREE interrupted run
     * (a resumable stop) resumes in its own mode unless a full run is forced, so
     * a scheduled tick never supersedes an interrupted forced full resync.
     *
     * Whether the run is fresh or resumed is decided atomically by the
     * repository (never pre-clear run_phase here): a resumed run keeps its
     * phase, cursor, idempotency key, counters and ORIGINAL run_started_at.
     * One exception: a forced full start that resumed a stale-locked
     * INCREMENTAL run converts it in place (we hold the lock) into a fresh
     * full run.
     *
     * A `manual` trigger ignores `next_attempt_after` (the admin asked
     * explicitly); every other trigger respects it.
     */
    public function start(string $trigger, bool $forceFull, ?int $now = null): ?string
    {
        $now ??= $this->now();
        $this->lastStartReason = null;

        if (!$this->settings->isEnabled()) {
            return $this->refuse('disabled');
        }

        $s = $this->state->get();
        $nextAttempt = self::intOrNull($s['next_attempt_after']);
        if ($trigger !== 'manual' && $nextAttempt !== null && $nextAttempt > $now) {
            return $this->refuse('backoff');
        }

        $mode     = $this->modeFor($s, $forceFull);
        $runId    = $this->newRunId();
        $acquired = $this->state->acquireLock($runId, $now, $now - self::STALE_LOCK_SECONDS, $mode, $trigger);
        if ($acquired === null) {
            return $this->refuse('locked');
        }

        if ($forceFull && $acquired === WokeometerSyncStateRepository::ACQUIRED_RESUMED
            && $this->state->get()['run_mode'] !== self::MODE_FULL) {
            $this->state->update([
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
            $acquired = WokeometerSyncStateRepository::ACQUIRED_FRESH;
        }

        $this->state->update($acquired === WokeometerSyncStateRepository::ACQUIRED_FRESH
            ? ['last_status' => self::STATUS_RUNNING, 'last_error_code' => null, 'last_error_message' => null]
            : ['last_status' => self::STATUS_RUNNING]);

        return $runId;
    }

    /**
     * Process up to MAX_PAGES_PER_CHUNK pages of the run. Never throws: any
     * Throwable becomes a RESUMABLE `error` stop (sanitized class name as the
     * message, INTERNAL_ERROR_BACKOFF_SECONDS = 6 h, key kept → the in-flight
     * page replays for free within the 24 h window)
     * with the lock released.
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
            'nextDueAt'           => $lastSuccess !== null ? $lastSuccess + WokeometerSettings::SYNC_INTERVAL_DAYS * 86_400 : null,
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

                if (!$this->state->heartbeat($runId, $this->now())) {
                    return self::notOwner();
                }
                $s = $this->state->get();

                $phase = self::stringOrNull($s['run_phase']);
                if ($phase !== 'movie' && $phase !== 'tv') {
                    return $this->stop($runId, self::STATUS_ERROR, null, 'invalid run phase', self::ERROR_BACKOFF_SECONDS, false);
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
                    $this->state->update(['run_idempotency_key' => $key]);
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

                        if ($result->nextCursor === null || $result->rows === []) {
                            $next = self::NEXT_PHASE[$phase];
                            if ($next === 'done') {
                                return $this->finish($runId, $s, $fields);
                            }
                            $fields['run_phase']  = $next;
                            $fields['run_cursor'] = null;
                        } elseif ($result->nextCursor === $cursor) {
                            return $this->stop($runId, self::STATUS_ERROR, $result->httpCode, 'cursor did not advance', self::ERROR_BACKOFF_SECONDS, false, $fields);
                        } else {
                            $fields['run_cursor'] = $result->nextCursor;
                        }

                        $this->state->update($fields);
                        if ($runRequests >= self::REQUEST_CAP_PER_RUN) {
                            return $this->stop($runId, self::STATUS_REQUEST_CAP, null, 'request cap reached', self::ERROR_BACKOFF_SECONDS, false);
                        }
                        break;

                    case WokeometerPageResult::RATE_LIMITED:
                        $this->state->update($fields);
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
                        $this->state->update($fields);
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
     * sweep unseen rows after a FULL run, copy the run stats, release the lock.
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

        // A full run that stored nothing at all is far more likely an API
        // glitch than an empty catalog: never let it wipe the local mirror.
        if ($full && $records > 0) {
            $this->titles->sweepUnseen($startedAt);
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

        $this->state->update($fields);
        $this->state->releaseLock($runId);

        return new WokeometerChunkResult(WokeometerChunkResult::DONE);
    }

    /**
     * End the run without finishing: record the status, copy the run stats,
     * set the backoff, reset the transient budget, release the lock and log
     * one warning. `$resumable` keeps phase/cursor/key/started_at (see the
     * class-level stop table); only the loop guard and the request cap clear
     * them so the next start is fresh.
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

        $this->state->update($fields);
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

    /** @param array<string, int|string|null> $s */
    private function modeFor(array $s, bool $forceFull): string
    {
        if ($forceFull) {
            return self::MODE_FULL;
        }
        if ($s['lock_run_id'] === null && self::isUnfinished($s['run_phase'])
            && in_array($s['run_mode'], [self::MODE_FULL, self::MODE_INCREMENTAL], true)) {
            return (string) $s['run_mode'];
        }

        return self::intOrNull($s['full_sync_completed_at']) === null ? self::MODE_FULL : self::MODE_INCREMENTAL;
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
