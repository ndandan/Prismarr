<?php

namespace App\Tests\Scheduler;

use App\Message\WokeometerSyncTick;
use App\Scheduler\WokeometerScheduleProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Trigger\CronExpressionTrigger;

class WokeometerScheduleProviderTest extends TestCase
{
    public function testOneHourlyCronTickAndNoCacheStateOrLock(): void
    {
        $schedule = (new WokeometerScheduleProvider())->getSchedule();

        $messages = $schedule->getRecurringMessages();
        $this->assertCount(1, $messages);
        $recurring = array_values($messages)[0];

        $trigger = $recurring->getTrigger();
        $this->assertInstanceOf(CronExpressionTrigger::class, $trigger, 'wall-clock cron, not a PeriodicalTrigger that re-phases on every consumer recycle');
        // '#hourly' = '# * * * *' with a hashed minute.
        $this->assertMatchesRegularExpression('/^([0-9]|[1-5][0-9]) \* \* \* \*$/', (string) $trigger);

        $context = new MessageContext('wokeometer', $recurring->getId(), $trigger, new \DateTimeImmutable());
        $emitted = iterator_to_array($recurring->getMessages($context), false);
        $this->assertCount(1, $emitted);
        $this->assertInstanceOf(WokeometerSyncTick::class, $emitted[0]);

        // Due-ness lives in SQLite: cache.app is wiped on settings saves and symfony/lock is absent.
        $this->assertNull($schedule->getState());
        $this->assertNull($schedule->getLock());
    }

    public function testRegisteredUnderTheWokeometerName(): void
    {
        $attrs = (new \ReflectionClass(WokeometerScheduleProvider::class))->getAttributes(AsSchedule::class);
        $this->assertCount(1, $attrs);
        $this->assertSame('wokeometer', $attrs[0]->newInstance()->name);
    }
}
