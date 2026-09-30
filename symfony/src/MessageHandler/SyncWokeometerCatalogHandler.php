<?php

namespace App\MessageHandler;

use App\Message\SyncWokeometerCatalog;
use App\Service\Wokeometer\WokeometerChunkResult;
use App\Service\Wokeometer\WokeometerSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Runs one chunk (≤ MAX_PAGES_PER_CHUNK pages) of a Wokeometer sync and, when
 * the run is not finished, re-dispatches itself with a DelayStamp so other
 * `async` work (SWR refreshes) interleaves between chunks.
 *
 * NEVER throws: `async` retries a throwing handler 3× — for a pay-per-request
 * API that would re-bill pages. The sync service already records failures in
 * the state row; anything that still escapes is logged at warning (class
 * name only — never getMessage(), which could carry provider text) and acked.
 * If the continuation dispatch itself fails, the run's lock simply goes
 * stale and the next hourly tick takes it over from the persisted cursor.
 */
#[AsMessageHandler]
final class SyncWokeometerCatalogHandler
{
    /** Continuation delay bounds (seconds). */
    private const MIN_DELAY = 1;
    private const MAX_DELAY = 3600;

    public function __construct(
        private readonly WokeometerSyncService $sync,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(SyncWokeometerCatalog $message): void
    {
        try {
            $runId = $message->runId ?? $this->sync->start($message->trigger, $message->forceFull);
            if ($runId === null) {
                // disabled / backoff / locked — expected, nothing to record
                // (the app logger drops info, and the state row is untouched).
                return;
            }

            $result = $this->sync->runChunk($runId);
            if ($result->status !== WokeometerChunkResult::CONTINUE) {
                return;
            }

            $delay = max(self::MIN_DELAY, min(self::MAX_DELAY, $result->delaySeconds));
            $this->bus->dispatch(
                new SyncWokeometerCatalog($runId, $message->trigger, $message->forceFull),
                [new DelayStamp($delay * 1000)],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Wokeometer sync chunk failed', [
                'path'    => 'messenger:' . SyncWokeometerCatalog::class,
                'code'    => 0,
                'message' => WokeometerSyncService::throwableLabel($e),
            ]);
        }
    }
}
