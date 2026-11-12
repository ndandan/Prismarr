<?php

namespace App\Tests\Service\Unifi;

use App\Service\Unifi\WanActivity;
use PHPUnit\Framework\TestCase;

/**
 * Network 11.x v2 aggregated-dashboard → the {ts, downBytes, upBytes} series
 * the charts take. Fixture values are lifted from the 2026-11-12 live probe of
 * a Network 11.0.99 console (docs/superpowers/probes, gitignored).
 */
class WanActivityTest extends TestCase
{
    private static function dash(array $history, array $speed = []): array
    {
        return [
            'wan_activity' => ['total_activity' => ['history' => $history]],
            'speed_test'   => ['data' => $speed],
        ];
    }

    /** A real sample row (client_count > 0). */
    private static function row(int $tsMs, float $rx, float $tx, int $clients = 52): array
    {
        return ['timestamp' => $tsMs, 'avg_rx_rate_bps' => $rx, 'avg_tx_rate_bps' => $tx, 'client_count' => $clients];
    }

    /** A bucket the new stats engine has no sample for (before the migration / in progress). */
    private static function empty(int $tsMs): array
    {
        return ['timestamp' => $tsMs, 'avg_rx_rate_bps' => 0, 'avg_tx_rate_bps' => 0, 'client_count' => 0];
    }

    public function testHourlyRatesBecomeBytesPerBucketWithRxAsDownload(): void
    {
        // Out of order on purpose: the series must come back ascending.
        $s = WanActivity::usage(self::dash([
            self::row(1794495600000, 6385881, 2736800),
            self::row(1794492000000, 380900, 1646159),
        ]));

        $this->assertSame(1794492000, $s[0]['ts']);              // ms → s
        // The field is named "bps" but carries BYTES/s: the probe's points ×
        // spacing summed exactly to summary.rx_bytes. So bytes = rate × 3600.
        $this->assertSame(380900.0 * 3600, $s[0]['downBytes']);  // rx = download
        $this->assertSame(1646159.0 * 3600, $s[0]['upBytes']);
        $this->assertSame(6385881.0 * 3600, $s[1]['downBytes']);
    }

    public function testBucketSpacingIsReadFromTheTimestamps(): void
    {
        $s = WanActivity::usage(self::dash([
            self::row(1794501000000, 100.0, 10.0),
            self::row(1794501300000, 200.0, 20.0),
            self::row(1794501600000, 300.0, 30.0),
        ]));

        $this->assertSame(300.0 * 300, $s[2]['downBytes']); // 5-minute buckets → × 300
    }

    public function testFiveMinuteBucketsCanBeRolledUpToHours(): void
    {
        $base = 1794499200000; // 16:00Z
        $rows = [];
        for ($i = 0; $i < 12; $i++) {
            $rows[] = self::row($base - 3600000 + $i * 300000, 10.0, 1.0); // 15:00–15:55
        }
        $rows[] = self::row($base, 20.0, 2.0);                               // 16:00
        $rows[] = self::row($base + 300000, 20.0, 2.0);                      // 16:05

        $s = WanActivity::usage(self::dash($rows), 3600);

        $this->assertCount(2, $s);
        $this->assertSame(1794495600, $s[0]['ts']);
        $this->assertSame(12 * 10.0 * 300, $s[0]['downBytes']);
        $this->assertSame(12 * 1.0 * 300, $s[0]['upBytes']);
        $this->assertSame(1794499200, $s[1]['ts']);
        $this->assertSame(2 * 20.0 * 300, $s[1]['downBytes']);
    }

    public function testBucketsWithoutASampleAreTrimmedFromBothEndsButKeptInside(): void
    {
        $h = 3600000;
        $s = WanActivity::usage(self::dash([
            self::empty(1794400000000),          // before the 11.x migration
            self::empty(1794400000000 + $h),
            self::row(1794400000000 + 2 * $h, 5.0, 5.0),
            self::empty(1794400000000 + 3 * $h), // an outage mid-series is real data
            self::row(1794400000000 + 4 * $h, 5.0, 5.0),
            self::empty(1794400000000 + 5 * $h), // the in-progress bucket
        ]));

        $this->assertCount(3, $s);
        $this->assertSame(1794400000 + 2 * 3600, $s[0]['ts']);
        $this->assertSame(0.0, $s[1]['downBytes']);
        $this->assertSame(1794400000 + 4 * 3600, $s[2]['ts']);
    }

    public function testNothingUsableIsNull(): void
    {
        $this->assertNull(WanActivity::usage(null));
        $this->assertNull(WanActivity::usage([]));
        $this->assertNull(WanActivity::usage(['wan_activity' => ['total_activity' => ['history' => 'x']]]));
        $this->assertNull(WanActivity::usage(self::dash([self::empty(1794400000000), self::empty(1794403600000)])));
        $this->assertNull(WanActivity::usage(self::dash(['junk', ['timestamp' => 'nope']])));
    }

    public function testSpeedtestIsTheLatestRealRunInsideTheWindow(): void
    {
        $dash = self::dash([], [
            ['time' => 1791694788536, 'download_mbps' => 1631, 'upload_mbps' => 1974, 'latency_ms' => 1, 'wan_networkgroup' => 'WAN'],
            // An unused second WAN reports a run with no time and zero speeds.
            ['time' => null, 'download_mbps' => 0, 'upload_mbps' => 0, 'latency_ms' => 0, 'wan_networkgroup' => 'WAN2'],
            ['time' => 1791000000000, 'download_mbps' => 900, 'upload_mbps' => 800, 'latency_ms' => 3, 'wan_networkgroup' => 'WAN2'],
        ]);

        $s = WanActivity::speedtests($dash, 1790000000);

        $this->assertSame([['ts' => 1791694789, 'downMbps' => 1631.0, 'upMbps' => 1974.0, 'latencyMs' => 1]], $s);
    }

    public function testSpeedtestOlderThanTheWindowOrMissingIsNull(): void
    {
        $dash = self::dash([], [['time' => 1791694788536, 'download_mbps' => 1631, 'upload_mbps' => 1974, 'latency_ms' => 1]]);

        $this->assertNull(WanActivity::speedtests($dash, 1791694789 + 1));
        $this->assertNull(WanActivity::speedtests(null, 0));
        $this->assertNull(WanActivity::speedtests(self::dash([], [['time' => null, 'download_mbps' => 0]]), 0));
    }
}
