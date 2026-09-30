<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Lifts the PHP execution-time cap after every test.
 *
 * Several controllers call `set_time_limit(60)` as a per-request guard.
 * PHPUnit runs the whole suite in ONE CLI process, so the first functional
 * test that reaches such a controller caps the remaining CPU time of the
 * entire run at 60 s — a full single-process run then dies mid-suite with
 * "Maximum execution time of 60 seconds exceeded" (seen in CI once the suite
 * grew; per-directory batches only hid it). Resetting to unlimited after each
 * test restores the CLI default without touching the controllers.
 */
final class ResetTimeLimitExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new class implements FinishedSubscriber {
            public function notify(Finished $event): void
            {
                set_time_limit(0);
            }
        });
    }
}
