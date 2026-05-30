<?php

namespace App\Tests\Service\Media\Usenet;

use App\Service\Media\Usenet\UsenetStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the status vocabulary mapping for both downloaders. The sidebar badge
 * and the page filter key off the canonical set, so a SABnzbd "Extracting" or
 * an NZBGet "UNPACKING" must both resolve to UsenetStatus::EXTRACTING.
 */
class UsenetStatusTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function sabnzbdProvider(): array
    {
        return [
            ['Downloading', UsenetStatus::DOWNLOADING],
            ['Queued',      UsenetStatus::QUEUED],
            ['Paused',      UsenetStatus::PAUSED],
            ['Repairing',   UsenetStatus::VERIFYING],
            ['Extracting',  UsenetStatus::EXTRACTING],
            ['Completed',   UsenetStatus::COMPLETED],
            ['Failed',      UsenetStatus::FAILED],
            ['Whatever',    UsenetStatus::UNKNOWN],
        ];
    }

    #[DataProvider('sabnzbdProvider')]
    public function testFromSabnzbd(string $raw, string $expected): void
    {
        self::assertSame($expected, UsenetStatus::fromSabnzbd($raw));
    }

    /** @return array<string, array{string, string}> */
    public static function nzbgetQueueProvider(): array
    {
        return [
            ['DOWNLOADING', UsenetStatus::DOWNLOADING],
            ['QUEUED',      UsenetStatus::QUEUED],
            ['PAUSED',      UsenetStatus::PAUSED],
            ['UNPACKING',   UsenetStatus::EXTRACTING],
            ['POST_PROCESSING', UsenetStatus::MOVING],
            ['???',         UsenetStatus::UNKNOWN],
        ];
    }

    #[DataProvider('nzbgetQueueProvider')]
    public function testFromNzbgetQueue(string $raw, string $expected): void
    {
        self::assertSame($expected, UsenetStatus::fromNzbgetQueue($raw));
    }

    /** @return array<string, array{string, string}> */
    public static function nzbgetHistoryProvider(): array
    {
        return [
            ['SUCCESS/ALL',    UsenetStatus::COMPLETED],
            ['WARNING/REPAIR', UsenetStatus::COMPLETED],
            ['FAILURE/PAR',    UsenetStatus::FAILED],
            ['DELETED/MANUAL', UsenetStatus::FAILED],
            ['NOPE',           UsenetStatus::UNKNOWN],
        ];
    }

    #[DataProvider('nzbgetHistoryProvider')]
    public function testFromNzbgetHistory(string $raw, string $expected): void
    {
        self::assertSame($expected, UsenetStatus::fromNzbgetHistory($raw));
    }
}
