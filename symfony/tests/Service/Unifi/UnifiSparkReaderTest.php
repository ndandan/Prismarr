<?php

namespace App\Tests\Service\Unifi;

use App\Service\Unifi\UnifiSparkReader;
use PHPUnit\Framework\TestCase;

class UnifiSparkReaderTest extends TestCase
{
    private const ID  = 'aabbccddeeff';
    private const NOW = 1751800000;

    /** @return array{0: UnifiSparkReader, 1: StubUnifiFetcher} */
    private function reader(array $responses, bool $fail = false): array
    {
        $stub   = new StubUnifiFetcher($responses, $fail);
        $reader = new UnifiSparkReader($stub);
        $reader->nowOverride = self::NOW;
        return [$reader, $stub];
    }

    private function rows(): array
    {
        return [
            ['time' => 1751799600000, 'rx_bytes' => 4.0e6, 'tx_bytes' => 9.0e7],
            ['time' => 1751799300000, 'rx_bytes' => 1.0e6, 'tx_bytes' => 2.0e7],
        ];
    }

    public function testPostsColonMacForLastHour(): void
    {
        [$reader, $stub] = $this->reader(['5minutes.user' => $this->rows()]);
        $reader->read(self::ID);

        $this->assertSame(['/stat/report/5minutes.user'], $stub->paths);
        $body = $stub->bodies[0];
        $this->assertSame(['time', 'rx_bytes', 'tx_bytes'], $body['attrs']);
        $this->assertSame(['aa:bb:cc:dd:ee:ff'], $body['macs']);
        $this->assertSame(3_600_000, $body['end'] - $body['start']);
        $this->assertSame(self::NOW * 1000, $body['end']);
    }

    public function testMapsDirectionAndSorts(): void
    {
        [$reader] = $this->reader(['5minutes.user' => $this->rows()]);
        $r = $reader->read(self::ID);

        // ms → s, unsorted input sorted ascending.
        $this->assertSame(1751799300, $r[0]['ts']);
        $this->assertSame(1751799600, $r[1]['ts']);
        // Client perspective: the AP's tx_bytes is what the client DOWNLOADED.
        $this->assertSame(2.0e7, $r[0]['downBytes']);
        $this->assertSame(1.0e6, $r[0]['upBytes']);
        $this->assertSame(9.0e7, $r[1]['downBytes']);
        $this->assertSame(4.0e6, $r[1]['upBytes']);
    }

    /**
     * Network 11.x: 5minutes.user answers ok with zero rows. Points from
     * v2 app-traffic-rate as a live 11.0.99 console returned them
     * (2026-11-12 probe): rates in BYTES/s from the CLIENT's side — tx is
     * what it sent (a camera's big number) — over `interval_seconds`
     * (300, or less for the bucket in progress).
     */
    private function v2Points(): array
    {
        return [
            ['timestamp' => 1751799600000, 'interval_seconds' => 182, 'rx_byte-r' => 7166,
             'tx_byte-r' => 292614, 'total_bytes' => 54560153, 'top_app' => ['application' => 172]],
            ['timestamp' => 1751799300000, 'interval_seconds' => 300, 'rx_byte-r' => 8195,
             'tx_byte-r' => 346003, 'total_bytes' => 106259607],
        ];
    }

    public function testEmptyClassicReportFallsBackToV2AppTrafficRate(): void
    {
        [$reader, $stub] = $this->reader(['5minutes.user' => [], 'app-traffic-rate' => $this->v2Points()]);
        $r = $reader->read(self::ID);

        $this->assertSame(
            [['ts' => 1751799300, 'downBytes' => 8195.0 * 300, 'upBytes' => 346003.0 * 300],
             ['ts' => 1751799600, 'downBytes' => 7166.0 * 182, 'upBytes' => 292614.0 * 182]],
            $r,
        );
        // start/end are epoch MILLISECONDS (seconds return nothing); the MAC
        // goes in the body, with colons.
        $this->assertSame(
            ['/app-traffic-rate?start=' . (self::NOW - 3600) * 1000 . '&end=' . self::NOW * 1000 . '&includeUnidentified=true'],
            $stub->v2Paths,
        );
        $this->assertSame([['client_macs' => ['aa:bb:cc:dd:ee:ff']]], $stub->v2Bodies);
    }

    public function testV2BucketWithoutIntervalAssumesFiveMinutes(): void
    {
        [$reader] = $this->reader(['5minutes.user' => [], 'app-traffic-rate' => [
            ['timestamp' => 1751799300000, 'rx_byte-r' => 10, 'tx_byte-r' => 20],
            'junk',
            ['timestamp' => 'nope', 'rx_byte-r' => 1],
        ]]);

        $this->assertSame([['ts' => 1751799300, 'downBytes' => 3000.0, 'upBytes' => 6000.0]], $reader->read(self::ID));
    }

    public function testWorkingClassicReportNeverTouchesV2(): void
    {
        [$reader, $stub] = $this->reader(['5minutes.user' => $this->rows(), 'app-traffic-rate' => $this->v2Points()]);
        $reader->read(self::ID);

        $this->assertSame([], $stub->v2Paths);
    }

    public function testDeadConsoleDoesNotTryV2(): void
    {
        [$reader, $stub] = $this->reader([], fail: true);

        $this->assertNull($reader->read(self::ID));
        $this->assertSame([], $stub->v2Paths);
    }

    public function testRejectsMalformedId(): void
    {
        [$reader, $stub] = $this->reader(['5minutes.user' => $this->rows()]);

        $this->assertNull($reader->read('../x'));
        $this->assertNull($reader->read('AABBCCDDEEFF'));  // uppercase
        $this->assertNull($reader->read('aabbccddeef'));   // 11 chars
        $this->assertNull($reader->read('aabbccddeeff0')); // 13 chars
        $this->assertNull($reader->read("aabbccddeeff\n")); // $ would let a trailing newline through
        $this->assertSame([], $stub->paths);               // no fetch for any of them
    }

    // The memo is a per-request dedupe only (the service is reset between
    // requests — see testResetClearsMemo), so these pin in-request behaviour.
    public function testMemoisesPerIdWithinOneRequest(): void
    {
        [$reader, $stub] = $this->reader(['5minutes.user' => $this->rows()]);

        $this->assertSame($reader->read(self::ID), $reader->read(self::ID));
        $this->assertCount(1, $stub->paths);

        // A different client is its own memo entry and its own query.
        $reader->read('112233445566');
        $this->assertCount(2, $stub->paths);
        $this->assertSame(['11:22:33:44:55:66'], $stub->bodies[1]['macs']);
    }

    public function testMemoExpiresAfterTtl(): void
    {
        [$reader, $stub] = $this->reader(['5minutes.user' => $this->rows()]);

        $reader->read(self::ID);
        $reader->nowOverride = self::NOW + 299;
        $reader->read(self::ID);
        $this->assertCount(1, $stub->paths); // still fresh

        $reader->nowOverride = self::NOW + 300;
        $reader->read(self::ID);
        $this->assertCount(2, $stub->paths); // expired → refetched
    }

    public function testNoRowsReturnsNullAndIsNotMemoised(): void
    {
        [$reader, $stub] = $this->reader(['5minutes.user' => []]);

        $this->assertNull($reader->read(self::ID));
        $this->assertNull($reader->read(self::ID));
        $this->assertCount(2, $stub->paths); // retried, not served from the memo
    }

    public function testFetchFailureReturnsNullAndIsNotMemoised(): void
    {
        [$reader, $stub] = $this->reader([], fail: true);

        $this->assertNull($reader->read(self::ID));
        $this->assertNull($reader->read(self::ID));
        $this->assertCount(2, $stub->paths);
    }

    public function testGarbageRowsSkippedNotFatal(): void
    {
        [$reader] = $this->reader(['5minutes.user' => [
            'not-an-array',
            ['time' => 'nope', 'rx_bytes' => 1.0],
            ['time' => 1751799300000, 'rx_bytes' => 'x', 'tx_bytes' => null],
            ['time' => 1751799600000, 'rx_bytes' => 5.0e5, 'tx_bytes' => 1.0e6],
        ]]);
        $r = $reader->read(self::ID);

        $this->assertCount(2, $r);                    // only rows with a usable ts
        $this->assertSame(0.0, $r[0]['downBytes']);   // non-numeric byte count → 0.0
        $this->assertSame(0.0, $r[0]['upBytes']);
        $this->assertSame(1.0e6, $r[1]['downBytes']);
    }

    // What services_resetter (worker mode) does between requests: the memo is
    // gone, so nothing carries over from one request to the next.
    public function testResetClearsMemo(): void
    {
        [$reader, $stub] = $this->reader(['5minutes.user' => $this->rows()]);

        $reader->read(self::ID);
        $reader->reset();
        $reader->read(self::ID);
        $this->assertCount(2, $stub->paths);
    }
}
