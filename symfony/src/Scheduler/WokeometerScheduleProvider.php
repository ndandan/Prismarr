<?php

namespace App\Scheduler;

use App\Message\WokeometerSyncTick;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Hourly, wall-clock-anchored Wokeometer tick (transport
 * `scheduler_wokeometer`, consumed by the s6 messenger-worker next to
 * `async`). The monthly cadence is NOT expressed here: due-ness lives in the
 * SQLite sync state and is checked by WokeometerSyncTickHandler, so a
 * missed tick (container down) is harmless — the next one sees it overdue.
 *
 * `cron`, not `every('1 hour')`: a PeriodicalTrigger without `from`
 * re-phases on every consumer recycle (--time-limit=3600 ≈ the interval).
 * No stateful() (cache.app is wiped on settings saves and not persisted)
 * and no lock() (symfony/lock is not installed; the DB lock guards runs).
 */
#[AsSchedule('wokeometer')]
final class WokeometerScheduleProvider implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())->add(RecurringMessage::cron('#hourly', new WokeometerSyncTick()));
    }
}
