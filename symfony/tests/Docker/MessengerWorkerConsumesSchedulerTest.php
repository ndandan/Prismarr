<?php

namespace App\Tests\Docker;

use PHPUnit\Framework\TestCase;

/**
 * Guard: the s6 messenger-worker must consume the `scheduler_wokeometer`
 * transport, or the #[AsSchedule('wokeometer')] hourly tick silently never
 * runs (the only symptom would be "the monthly sync never happens").
 *
 * The source file lives at the repo root (one level above symfony/). The
 * dev/test container only mounts symfony/, and CI runs the suite inside the
 * image built from this checkout, where the same file was COPYed to
 * /etc/s6-overlay/s6-rc.d/ (docker/frankenphp/Dockerfile). Prefer the
 * source, fall back to the baked copy, skip only when neither exists.
 */
class MessengerWorkerConsumesSchedulerTest extends TestCase
{
    private const CANDIDATES = [
        __DIR__ . '/../../../docker/frankenphp/s6/messenger-worker/run',
        '/etc/s6-overlay/s6-rc.d/messenger-worker/run',
    ];

    public function testWorkerConsumesAsyncAndTheWokeometerSchedule(): void
    {
        $path = null;
        foreach (self::CANDIDATES as $candidate) {
            if (is_file($candidate)) {
                $path = $candidate;
                break;
            }
        }
        if ($path === null) {
            $this->markTestSkipped('messenger-worker s6 run file not reachable from this environment');
        }

        $run = (string) file_get_contents($path);
        $this->assertStringContainsString('messenger:consume async scheduler_wokeometer', $run, $path);
        $this->assertStringContainsString('--time-limit=3600 --memory-limit=512M', $run, $path);
    }
}
