<?php

namespace App\Tests\Service\Media;

use App\Service\Media\RadarrClient;
use App\Service\Media\ServiceHealthCache;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Radarr 7 lists every audio and subtitle track (MediaInfoResource
 * audioStreams[] / subtitleStreams[]); the flat audioCodec / subtitles
 * fields are obsolete. The film detail normaliser passes the per-track
 * lists through, and leaves them null on Radarr 6 or older.
 */
#[AllowMockObjectsWithoutExpectations]
class RadarrMediaInfoStreamsTest extends TestCase
{
    private function normalize(array $mediaInfo): array
    {
        $client = new RadarrClient(
            $this->createMock(ServiceInstanceProvider::class),
            new NullLogger(),
            new ServiceHealthCache(new ArrayAdapter()),
        );
        $movie = ['id' => 1, 'title' => 'Dune', 'hasFile' => true, 'movieFile' => [
            'id' => 7, 'quality' => ['quality' => ['name' => 'Bluray-2160p']], 'mediaInfo' => $mediaInfo,
        ]];

        return $client->normalizeMovies([$movie])[0]['movieFile']['mediaInfo'];
    }

    public function testRadarr7TrackListsArePassedThrough(): void
    {
        $mi = $this->normalize([
            'audioCodec' => 'TrueHD Atmos', 'audioChannels' => 7.1, 'subtitles' => 'eng/spa',
            'audioStreams' => [
                ['language' => 'eng', 'codec' => 'TrueHD Atmos', 'profile' => 'x', 'bitrate' => 1, 'channels' => 7.1, 'channelPositions' => 'x', 'title' => 'Main'],
                ['language' => 'fre', 'codec' => 'AC3', 'channels' => 5.1, 'title' => null],
            ],
            'subtitleStreams' => [
                ['language' => 'eng', 'format' => 'PGS', 'title' => null, 'forced' => true,  'hearingImpaired' => false],
                ['language' => 'spa', 'format' => 'SRT', 'title' => 'SDH', 'forced' => null,  'hearingImpaired' => true],
            ],
        ]);

        self::assertSame([
            ['language' => 'eng', 'codec' => 'TrueHD Atmos', 'channels' => 7.1, 'title' => 'Main'],
            ['language' => 'fre', 'codec' => 'AC3',          'channels' => 5.1, 'title' => null],
        ], $mi['audioStreams']);
        self::assertSame([
            ['language' => 'eng', 'forced' => true,  'hearingImpaired' => false],
            ['language' => 'spa', 'forced' => false, 'hearingImpaired' => true],
        ], $mi['subtitleStreams']);
        // The flat fields stay for the Radarr ≤ 6 rendering path.
        self::assertSame('TrueHD Atmos', $mi['audioCodec']);
        self::assertSame('eng/spa', $mi['subtitles']);
    }

    public function testOlderRadarrHasNoTrackLists(): void
    {
        $mi = $this->normalize(['audioCodec' => 'DTS', 'audioChannels' => 5.1, 'subtitles' => 'English']);

        self::assertNull($mi['audioStreams']);
        self::assertNull($mi['subtitleStreams']);
    }

    public function testMalformedEntriesAreDropped(): void
    {
        $mi = $this->normalize([
            'audioStreams'    => ['junk', ['codec' => 'AAC', 'channels' => 2]],
            'subtitleStreams' => [null, ['language' => 'ger']],
        ]);

        self::assertSame([['language' => null, 'codec' => 'AAC', 'channels' => 2, 'title' => null]], $mi['audioStreams']);
        self::assertSame([['language' => 'ger', 'forced' => false, 'hearingImpaired' => false]], $mi['subtitleStreams']);
    }
}
