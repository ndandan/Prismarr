<?php

namespace App\Tests\Service\Media;

use App\Service\Media\HoundarrClient;
use PHPUnit\Framework\TestCase;

class HoundarrClientTest extends TestCase
{
    /** Raw payload exactly as documented (schema 1). */
    private function fixture(): array
    {
        return [
            'schema'       => 1,
            'generated_at' => '2026-05-22T18:00:00Z',
            'totals'       => [
                'tracked'     => 11,
                'eligible'    => 7,
                'gated'       => 2,
                'unreleased'  => 1,
                'searches_7d' => 1,
            ],
        ];
    }

    public function testNormalizesDocumentedPayload(): void
    {
        $out = HoundarrClient::normalizeWidget($this->fixture());

        self::assertSame(
            ['tracked' => 11, 'eligible' => 7, 'gated' => 2, 'unreleased' => 1, 'searches7d' => 1],
            $out['totals'],
        );
        self::assertSame(strtotime('2026-05-22T18:00:00Z'), $out['generatedAtEpoch']);
        self::assertNull($out['error']);
    }

    public function testClampsNegativesAndCastsStrings(): void
    {
        $out = HoundarrClient::normalizeWidget([
            'totals' => ['tracked' => '-3', 'eligible' => '5', 'gated' => -1, 'unreleased' => 2.9, 'searches_7d' => 'abc'],
        ]);

        self::assertSame(
            ['tracked' => 0, 'eligible' => 5, 'gated' => 0, 'unreleased' => 2, 'searches7d' => 0],
            $out['totals'],
        );
    }

    public function testMissingFieldsDefaultToZeroAndUnknownKeysAreDropped(): void
    {
        $out = HoundarrClient::normalizeWidget(['totals' => ['tracked' => 4, 'secret_path' => '/mnt/x'], 'api_key' => 'leak']);

        self::assertSame(
            ['tracked' => 4, 'eligible' => 0, 'gated' => 0, 'unreleased' => 0, 'searches7d' => 0],
            $out['totals'],
        );
        self::assertSame(['totals', 'generatedAtEpoch', 'error'], array_keys($out));
    }

    public function testBadOrMissingGeneratedAtIsNull(): void
    {
        self::assertNull(HoundarrClient::normalizeWidget(['totals' => [], 'generated_at' => 'not-a-date'])['generatedAtEpoch']);
        self::assertNull(HoundarrClient::normalizeWidget(['totals' => []])['generatedAtEpoch']);
    }

    public function testTotalsNotAnArrayIsTreatedAsEmpty(): void
    {
        $out = HoundarrClient::normalizeWidget(['totals' => 'nope']);
        self::assertSame(0, $out['totals']['tracked']);
    }
}
