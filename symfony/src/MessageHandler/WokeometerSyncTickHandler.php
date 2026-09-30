<?php

namespace App\MessageHandler;

use App\Message\SyncWokeometerCatalog;
use App\Message\WokeometerSyncTick;
use App\Service\Wokeometer\WokeometerSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Hourly scheduler tick: one single-row SQLite read; when a sync is due
 * (see WokeometerSyncService::isDue()) it queues a scheduled start on
 * `async`. Silent when not due. Never throws — a scheduler message that
 * throws would only be noise; the next tick re-evaluates anyway.
 */
#[AsMessageHandler]
final class WokeometerSyncTickHandler
{
    public function __construct(
        private readonly WokeometerSyncService $sync,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(WokeometerSyncTick $tick): void
    {
        try {
            if (!$this->sync->isDue(time())) {
                return;
            }
            $this->bus->dispatch(new SyncWokeometerCatalog(null, 'schedule', false));
        } catch (\Throwable $e) {
            $this->logger->warning('Wokeometer sync tick failed', [
                'path'    => 'messenger:' . WokeometerSyncTick::class,
                'code'    => 0,
                'message' => WokeometerSyncService::throwableLabel($e),
            ]);
        }
    }
}
