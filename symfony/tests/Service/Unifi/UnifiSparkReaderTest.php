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

    public function testCachesPerId(): void
    {
        [$reader, $stub] = $this->reader(['5minutes.user' => $this->rows()]);

        $this->assertSame($reader->read(self::ID), $reader->read(self::ID));
        $this->assertCount(1, $stub->paths);

        // A different client is its own cache entry and its own query.
        $reader->read('112233445566');
        $this->assertCount(2, $stub->paths);
        $this->assertSame(['11:22:33:44:55:66'], $stub->bodies[1]['macs']);
    }

    public function testCacheExpiresAfterTtl(): void
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

    public function testExpiredEntriesArePrunedOnWrite(): void
    {
        [$reader] = $this->reader(['5minutes.user' => $this->rows()]);

        $reader->read(self::ID);
        $reader->read('112233445566');
        $this->assertCount(2, $this->cacheOf($reader));

        // Both are now stale; the next write must drop them, not just add one.
        $reader->nowOverride = self::NOW + 301;
        $reader->read('665544332211');
        // (strval: an all-digit id is an int array key.)
        $this->assertSame(['665544332211'], array_map('strval', array_keys($this->cacheOf($reader))));
    }

    public function testNoRowsReturnsNullAndIsNotCached(): void
    {
        [$reader, $stub] = $this->reader(['5minutes.user' => []]);

        $this->assertNull($reader->read(self::ID));
        $this->assertNull($reader->read(self::ID));
        $this->assertCount(2, $stub->paths); // retried, not served from cache
    }

    public function testFetchFailureReturnsNullAndIsNotCached(): void
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

    public function testResetClearsCache(): void
    {
        [$reader, $stub] = $this->reader(['5minutes.user' => $this->rows()]);

        $reader->read(self::ID);
        $reader->reset();
        $reader->read(self::ID);
        $this->assertCount(2, $stub->paths);
    }

    /** @return array<string, mixed> */
    private function cacheOf(UnifiSparkReader $reader): array
    {
        $p = new \ReflectionProperty($reader, 'cache');
        return $p->getValue($reader);
    }
}
