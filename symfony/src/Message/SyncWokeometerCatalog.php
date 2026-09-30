<?php

namespace App\Message;

/**
 * "Run (or continue) a Wokeometer catalog sync" — routed to `async`, handled
 * in the messenger worker only (every paid request happens there).
 *
 * `runId === null` asks the handler to START a run (subject to the enabled
 * flag, backoff and the DB lock); a non-null `runId` is a continuation of
 * that run, dispatched by the handler itself with a DelayStamp.
 */
final readonly class SyncWokeometerCatalog
{
    public function __construct(
        public ?string $runId = null,
        public string $trigger = 'schedule',
        public bool $forceFull = false,
    ) {}
}
