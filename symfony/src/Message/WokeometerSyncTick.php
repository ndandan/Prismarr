<?php

namespace App\Message;

/**
 * Hourly tick emitted by the `wokeometer` schedule (scheduler_wokeometer
 * transport — deliberately NOT routed). The handler only checks DB
 * due-ness and, when due, dispatches SyncWokeometerCatalog.
 *
 * Stringable because RecurringMessage::cron() requires it for a hashed
 * ('#hourly') expression: the string is the hash context picking the minute.
 */
final readonly class WokeometerSyncTick implements \Stringable
{
    public function __toString(): string
    {
        return 'wokeometer-sync-tick';
    }
}
