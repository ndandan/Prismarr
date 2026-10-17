<?php

namespace App\Tests\Service\Http;

use App\Service\Http\ConcurrentCurl;
use App\Tests\Support\LocalHttp;
use PHPUnit\Framework\TestCase;

/**
 * ConcurrentCurl runs plain "blocking" task closures so the curl transfers
 * they issue through ConcurrentCurl::exec() overlap. Every test here uses
 * real curl handles against local fixtures (see LocalHttp) so the timing
 * assertions measure actual transfer overlap, not mocks.
 */
class ConcurrentCurlTest extends TestCase
{
    private static function elapsedMs(float $start): float
    {
        return (hrtime(true) - $start) / 1e6;
    }

    public function testExecOutsideARunnerIsPlainCurlExec(): void
    {
        $ch = LocalHttp::blackholeHandle(200);
        $t  = hrtime(true);
        $r  = ConcurrentCurl::exec($ch);

        self::assertFalse($r);
        self::assertSame(CURLE_OPERATION_TIMEDOUT, curl_errno($ch));
        self::assertNotSame('', curl_error($ch));
        self::assertGreaterThan(150, self::elapsedMs($t));
    }

    public function testExecInsideAForeignFiberDoesNotSuspend(): void
    {
        // Only fibers the runner owns are cooperative — anyone else's fiber
        // (or a fiber left over from a previous request in worker mode) must
        // get a synchronous transfer, never a surprise suspension.
        $fiber = new \Fiber(static fn () => ConcurrentCurl::exec(LocalHttp::blackholeHandle(50)));
        $fiber->start();

        self::assertTrue($fiber->isTerminated());
        self::assertFalse($fiber->getReturn());
    }

    public function testTransfersOfDifferentTasksOverlap(): void
    {
        $tasks = [];
        foreach (range(1, 6) as $i) {
            $tasks["t{$i}"] = static function (): array {
                $ch = LocalHttp::blackholeHandle(400);
                $r  = ConcurrentCurl::exec($ch);
                // The task sees exactly what a serial curl_exec would leave behind.
                return [$r, curl_errno($ch), curl_error($ch) !== '', curl_getinfo($ch, CURLINFO_HTTP_CODE)];
            };
        }

        $t   = hrtime(true);
        $out = (new ConcurrentCurl())->run($tasks);
        $ms  = self::elapsedMs($t);

        self::assertSame(array_keys($tasks), array_keys($out));
        foreach ($out as $res) {
            self::assertSame(['value' => [false, CURLE_OPERATION_TIMEDOUT, true, 0]], $res);
        }
        // Serial would be ~2400 ms.
        self::assertLessThan(1200, $ms, "6 x 400 ms transfers took {$ms} ms");
    }

    public function testATaskMayIssueSeveralTransfersInSequence(): void
    {
        $tasks = [];
        foreach (['a', 'b', 'c'] as $k) {
            $tasks[$k] = static function (): int {
                $n = 0;
                foreach ([1, 2] as $_) {
                    ConcurrentCurl::exec(LocalHttp::blackholeHandle(300));
                    $n++;
                }
                return $n;
            };
        }

        $t   = hrtime(true);
        $out = (new ConcurrentCurl())->run($tasks);
        $ms  = self::elapsedMs($t);

        self::assertSame(['a' => ['value' => 2], 'b' => ['value' => 2], 'c' => ['value' => 2]], $out);
        // Serial would be ~1800 ms; overlapped ~600 ms.
        self::assertLessThan(1200, $ms, "3 tasks x 2 x 300 ms took {$ms} ms");
    }

    public function testExceptionsAreCapturedPerTask(): void
    {
        $out = (new ConcurrentCurl())->run([
            'ok'    => static fn (): string => 'fine',
            'boom'  => static function (): never {
                ConcurrentCurl::exec(LocalHttp::blackholeHandle(50));
                throw new \RuntimeException('after transfer');
            },
            'early' => static fn (): never => throw new \LogicException('before transfer'),
        ]);

        self::assertSame(['value' => 'fine'], $out['ok']);
        self::assertInstanceOf(\RuntimeException::class, $out['boom']['error'] ?? null);
        self::assertInstanceOf(\LogicException::class, $out['early']['error'] ?? null);
    }

    public function testEmptyRunIsANoOp(): void
    {
        self::assertSame([], (new ConcurrentCurl())->run([]));
    }

    public function testSuccessfulResponsesKeepBodyAndStatus(): void
    {
        $base = LocalHttp::serverUrl();
        if ($base === null) {
            self::markTestSkipped('PHP built-in server unavailable here');
        }

        $tasks = [];
        foreach ([['a', 200], ['b', 404], ['c', 200], ['d', 503]] as [$k, $code]) {
            $tasks[$k] = static function () use ($base, $k, $code): array {
                $ch = LocalHttp::serverHandle($base, 400, $code, "body-{$k}");
                $r  = ConcurrentCurl::exec($ch);
                return [$r, curl_getinfo($ch, CURLINFO_HTTP_CODE), curl_errno($ch)];
            };
        }

        $t   = hrtime(true);
        $out = (new ConcurrentCurl())->run($tasks);
        $ms  = self::elapsedMs($t);

        self::assertSame([
            'a' => ['value' => ['body-a', 200, 0]],
            'b' => ['value' => ['body-b', 404, 0]],
            'c' => ['value' => ['body-c', 200, 0]],
            'd' => ['value' => ['body-d', 503, 0]],
        ], $out);
        self::assertLessThan(1200, $ms, "4 x 400 ms responses took {$ms} ms");
    }

    public function testRunnerLeavesNoCooperativeStateBehind(): void
    {
        (new ConcurrentCurl())->run(['x' => static fn () => ConcurrentCurl::exec(LocalHttp::blackholeHandle(20))]);

        // After a run, a fresh fiber is foreign again (worker-mode hygiene).
        $fiber = new \Fiber(static fn () => ConcurrentCurl::exec(LocalHttp::blackholeHandle(20)));
        $fiber->start();
        self::assertTrue($fiber->isTerminated());
    }

    /**
     * A task that blocks before its first transfer (the SSRF guard's
     * synchronous DNS lookup, a hung resolver) must not eat into the timeout
     * budget of transfers other tasks already queued: no handle's clock may
     * start until every task has reached its first transfer. Before the fix,
     * the earlier task's handle was kicked on add, so a stall longer than its
     * timeout surfaced as CURLE_OPERATION_TIMEDOUT on a healthy service —
     * whatever its position in the task order.
     */
    public function testABlockingTaskSetupDoesNotTimeOutOtherTasksTransfers(): void
    {
        $base = LocalHttp::serverUrl();
        if ($base === null) {
            self::markTestSkipped('PHP built-in server unavailable here');
        }

        $fast = static function () use ($base): array {
            $ch = LocalHttp::serverHandle($base, 20, 200, 'fast');
            // Generous for a 20 ms answer, far shorter than the stall below.
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 300);
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, 400);
            $r = ConcurrentCurl::exec($ch);
            return [$r, curl_errno($ch), curl_getinfo($ch, CURLINFO_HTTP_CODE)];
        };
        $blocking = static function () use ($base): array {
            usleep(800_000); // synchronous setup, e.g. a stalled DNS lookup
            $ch = LocalHttp::serverHandle($base, 20, 200, 'slow-setup');
            $r  = ConcurrentCurl::exec($ch);
            return [$r, curl_errno($ch), curl_getinfo($ch, CURLINFO_HTTP_CODE)];
        };

        $out = (new ConcurrentCurl())->run([
            'a'       => $fast,
            'b'       => $fast,
            'stalled' => $blocking,
            'c'       => $fast,
        ]);

        self::assertSame([
            'a'       => ['value' => ['fast', 0, 200]],
            'b'       => ['value' => ['fast', 0, 200]],
            'stalled' => ['value' => ['slow-setup', 0, 200]],
            'c'       => ['value' => ['fast', 0, 200]],
        ], $out);
    }

    public function testTransfersStillOverlapWhenATaskBlocksBeforeItsTransfer(): void
    {
        $base = LocalHttp::serverUrl();
        if ($base === null) {
            self::markTestSkipped('PHP built-in server unavailable here');
        }

        $tasks = ['setup' => static function () use ($base): string|bool {
            usleep(300_000);
            return ConcurrentCurl::exec(LocalHttp::serverHandle($base, 400, 200, 'setup'));
        }];
        foreach (['a', 'b', 'c'] as $k) {
            $tasks[$k] = static fn (): string|bool => ConcurrentCurl::exec(LocalHttp::serverHandle($base, 400, 200, $k));
        }

        $t   = hrtime(true);
        $out = (new ConcurrentCurl())->run($tasks);
        $ms  = self::elapsedMs($t);

        self::assertSame(['setup' => ['value' => 'setup'], 'a' => ['value' => 'a'], 'b' => ['value' => 'b'], 'c' => ['value' => 'c']], $out);
        // 300 ms setup + one overlapped 400 ms round; serial would be ~1900 ms.
        self::assertLessThan(1300, $ms, "setup + 4 x 400 ms took {$ms} ms");
    }
}
