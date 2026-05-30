<?php

namespace App\Tests\Service\Media\Usenet;

use App\Service\ConfigService;
use App\Service\Media\ServiceHealthCache;
use App\Service\Media\Usenet\SabnzbdClient;
use App\Service\Media\Usenet\UsenetDownload;
use App\Service\Media\Usenet\UsenetStatus;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Pins the SABnzbd → normalized mapping. SABnzbd reports queue sizes as
 * MB-in-strings ("0.48") and history sizes as raw bytes; both must land in
 * {@see UsenetDownload} as bytes, and the downloader's status vocabulary must
 * fold into the canonical {@see UsenetStatus} set the UI and badge rely on.
 */
#[AllowMockObjectsWithoutExpectations]
class SabnzbdClientTest extends TestCase
{
    private function makeClient(): SabnzbdClient
    {
        $cfg = $this->createMock(ConfigService::class);
        $cfg->method('get')->willReturn('http://localhost');
        return new SabnzbdClient($cfg, new NullLogger(), $this->createMock(ServiceHealthCache::class));
    }

    private function call(string $method, mixed ...$args): mixed
    {
        $m = new ReflectionMethod(SabnzbdClient::class, $method);
        $m->setAccessible(true);
        return $m->invoke($this->makeClient(), ...$args);
    }

    public function testNormalizeQueueSlotConvertsMbStringsToBytes(): void
    {
        /** @var UsenetDownload $d */
        $d = $this->call('normalizeQueueSlot', [
            'status'     => 'Downloading',
            'filename'   => 'Prismarr.Test.File',
            'cat'        => 'movies',
            'mb'         => '1024',     // 1 GiB total
            'mbleft'     => '512',      // half remaining
            'percentage' => '50',
            'nzo_id'     => 'SABnzbd_nzo_abc',
            'timeleft'   => '0:02:30',
        ]);

        self::assertSame('SABnzbd_nzo_abc', $d->id);
        self::assertSame('Prismarr.Test.File', $d->name);
        self::assertSame(UsenetStatus::DOWNLOADING, $d->status);
        self::assertSame(1024 * 1024 * 1024, $d->sizeBytes);
        self::assertSame(512 * 1024 * 1024, $d->remainingBytes);
        self::assertSame(50.0, $d->percentage);
        self::assertSame('movies', $d->category);
        self::assertSame(150, $d->etaSeconds); // 2m30 = 150s
        self::assertFalse($d->isHistory);
        self::assertNull($d->failMessage);
    }

    public function testNormalizeHistorySlotUsesRawBytesAndFailMessage(): void
    {
        /** @var UsenetDownload $d */
        $d = $this->call('normalizeHistorySlot', [
            'status'       => 'Failed',
            'name'         => 'Prismarr.Test.File',
            'nzb_name'     => 'Prismarr.Test.File.nzb',
            'bytes'        => 500000,
            'category'     => 'tv',
            'fail_message' => 'No article found',
            'nzo_id'       => 'SABnzbd_nzo_xyz',
        ]);

        self::assertSame(UsenetStatus::FAILED, $d->status);
        self::assertSame(500000, $d->sizeBytes);
        self::assertSame(0, $d->remainingBytes);
        self::assertSame(0.0, $d->percentage);
        self::assertSame('No article found', $d->failMessage);
        self::assertTrue($d->isHistory);
    }

    public function testCompletedHistoryReportsFullPercentageAndNoFailMessage(): void
    {
        /** @var UsenetDownload $d */
        $d = $this->call('normalizeHistorySlot', [
            'status' => 'Completed',
            'name'   => 'Done',
            'bytes'  => 100,
            'fail_message' => '',
        ]);

        self::assertSame(UsenetStatus::COMPLETED, $d->status);
        self::assertSame(100.0, $d->percentage);
        self::assertNull($d->failMessage);
    }

    /** @return array<string, array{string, ?int}> */
    public static function clockProvider(): array
    {
        return [
            'hms'      => ['1:02:30', 3750],
            'ms'       => ['02:30', 150],
            'unknown'  => ['unknown', null],
            'empty'    => ['', null],
            'zero'     => ['0:00:00', null],
        ];
    }

    #[DataProvider('clockProvider')]
    public function testParseClock(string $clock, ?int $expected): void
    {
        self::assertSame($expected, $this->call('parseClock', $clock));
    }

    public function testGetKind(): void
    {
        self::assertSame('sabnzbd', $this->makeClient()->getKind());
    }
}
