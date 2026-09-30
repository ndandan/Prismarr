<?php

namespace App\Service\Wokeometer;

/**
 * Outcome of one WokeometerSyncService::runChunk() call.
 *
 *  - `continue` — the run still holds the lock; dispatch a continuation
 *                 after `delaySeconds` (chunk boundary, 429, transient).
 *  - `done`     — both phases finished; watermark committed, lock released.
 *  - `stopped`  — the run ended without finishing (`reason` says why: a
 *                 last_status value, or `not_owner` when this run no longer
 *                 holds the lock and did nothing).
 */
final readonly class WokeometerChunkResult
{
    public const CONTINUE = 'continue';
    public const DONE     = 'done';
    public const STOPPED  = 'stopped';

    public function __construct(
        public string $status,
        public int $delaySeconds = 0,
        public ?string $reason = null,
    ) {}
}
