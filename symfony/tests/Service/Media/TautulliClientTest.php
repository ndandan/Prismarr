<?php

namespace App\Tests\Service\Media;

use App\Service\Media\TautulliClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic coverage of TautulliClient::normalizeActivity() — the transform
 * from a raw Tautulli `get_activity` `data` object into the sanitized shape
 * the frontend receives. No network: the input is a captured-style fixture.
 *
 * The security-critical assertions are the sanitization ones: private fields
 * (IPs, tokens, machine id, file paths, Plex username/email) present in the
 * raw session must NOT survive into the normalized output.
 */
class TautulliClientTest extends TestCase
{
    /** A representative single-session get_activity `data` payload. */
    private function fixtureData(): array
    {
        return [
            'stream_count'               => '1',
            'stream_count_direct_play'   => 1,
            'stream_count_direct_stream' => 0,
            'stream_count_transcode'     => 0,
            'total_bandwidth'            => 8700,
            'lan_bandwidth'              => 8700,
            'wan_bandwidth'              => 0,
            'sessions'                   => [
                [
                    'session_key'        => '123',
                    'session_id'         => 'abc-session-id',
                    'state'              => 'playing',
                    'full_title'         => "Tom Clancy's Jack Ryan: Ghost War",
                    'title'              => 'Ghost War',
                    'grandparent_title'  => 'Tom Clancy\'s Jack Ryan',
                    'year'               => '2026',
                    'media_type'         => 'movie',
                    'thumb'              => '/library/metadata/12345/thumb/1700000000',
                    'friendly_name'      => 'nDanDan',
                    'username'           => 'plexlogin_secret',
                    'user'               => 'nDanDan',
                    'product'            => 'Plex for Windows',
                    'player'             => 'Main-PC',
                    'device'             => 'Windows',
                    'platform'           => 'Windows',
                    'quality_profile'    => 'Original',
                    'container_decision' => 'direct play',
                    'video_decision'     => 'direct play',
                    'audio_decision'     => 'direct play',
                    'subtitle_decision'  => 'direct play',
                    'transcode_decision' => 'direct play',
                    'location'           => 'lan',
                    'bandwidth'          => '8700',
                    'progress_percent'   => '36.4',
                    // ── Private fields that must be stripped ──────────────
                    'ip_address'         => '192.168.68.55',
                    'ip_address_public'  => '203.0.113.9',
                    'machine_id'         => 'mach-deadbeef',
                    'session_token'      => 'tok-should-not-leak',
                    'file'               => '/data/media/movies/JackRyan.mkv',
                    'email'              => 'user@example.com',
                ],
            ],
        ];
    }

    public function testNormalizesCountsAndBandwidth(): void
    {
        $out = TautulliClient::normalizeActivity($this->fixtureData());

        self::assertSame(1, $out['streamCount']);
        self::assertSame(1, $out['directPlayCount']);
        self::assertSame(0, $out['directStreamCount']);
        self::assertSame(0, $out['transcodeCount']);

        self::assertSame(8700, $out['bandwidth']['totalKbps']);
        self::assertSame(8.7, $out['bandwidth']['totalMbps']);
        self::assertSame(8.7, $out['bandwidth']['lanMbps']);
        self::assertSame(0.0, $out['bandwidth']['wanMbps']);
    }

    public function testNormalizesSessionFields(): void
    {
        $out = TautulliClient::normalizeActivity($this->fixtureData());
        self::assertCount(1, $out['sessions']);
        $s = $out['sessions'][0];

        self::assertSame('123', $s['sessionKey']);
        self::assertSame('playing', $s['state']);
        self::assertSame("Tom Clancy's Jack Ryan: Ghost War", $s['title']);
        self::assertSame('2026', $s['year']);
        self::assertSame('movie', $s['mediaType']);
        self::assertSame('nDanDan', $s['userDisplayName']);
        self::assertSame('Plex for Windows', $s['product']);
        self::assertSame('Original', $s['quality']);
        self::assertSame('direct play', $s['transcodeDecision']);
        self::assertSame('lan', $s['location']);
        self::assertSame(8700, $s['bandwidthKbps']);
        self::assertSame(8.7, $s['bandwidthMbps']);
        self::assertSame(36.4, $s['progressPercent']);
    }

    public function testStripsPrivateFields(): void
    {
        $out = TautulliClient::normalizeActivity($this->fixtureData());
        $s = $out['sessions'][0];

        // Whatever the allow-list grows to, none of these may ever appear.
        foreach (['ip_address', 'ip_address_public', 'machine_id', 'session_token', 'file', 'email', 'username'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $s, "private field {$forbidden} leaked");
        }
        // Belt-and-suspenders: the secret values must not appear under any key.
        $flat = implode('|', array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', $s));
        self::assertStringNotContainsString('plexlogin_secret', $flat);
        self::assertStringNotContainsString('tok-should-not-leak', $flat);
        self::assertStringNotContainsString('203.0.113.9', $flat);
        self::assertStringNotContainsString('/data/media/movies', $flat);
    }

    public function testEmptyActivity(): void
    {
        $out = TautulliClient::normalizeActivity([
            'stream_count' => 0,
            'sessions'     => [],
        ]);

        self::assertSame(0, $out['streamCount']);
        self::assertSame([], $out['sessions']);
        self::assertSame(0.0, $out['bandwidth']['totalMbps']);
    }

    public function testProgressPercentIsClamped(): void
    {
        $data = $this->fixtureData();
        $data['sessions'][0]['progress_percent'] = '150';
        $out = TautulliClient::normalizeActivity($data);
        self::assertSame(100.0, $out['sessions'][0]['progressPercent']);

        $data['sessions'][0]['progress_percent'] = 'not-a-number';
        $out = TautulliClient::normalizeActivity($data);
        self::assertSame(0.0, $out['sessions'][0]['progressPercent']);
    }

    public function testFallsBackToBareTitleWhenFullTitleMissing(): void
    {
        $data = $this->fixtureData();
        unset($data['sessions'][0]['full_title']);
        $out = TautulliClient::normalizeActivity($data);
        self::assertSame('Ghost War', $out['sessions'][0]['title']);
    }

    /**
     * Episodes carry a landscape `thumb` (the episode still); the portrait
     * series poster lives in `grandparent_thumb`. The poster tile wants the
     * portrait art, so episodes must resolve to grandparent_thumb.
     */
    public function testEpisodePosterPrefersGrandparentThumb(): void
    {
        $data = $this->fixtureData();
        $data['sessions'][0]['media_type']       = 'episode';
        $data['sessions'][0]['thumb']             = '/library/metadata/999/thumb/1';
        $data['sessions'][0]['grandparent_thumb'] = '/library/metadata/100/thumb/2';
        $out = TautulliClient::normalizeActivity($data);
        self::assertSame('/library/metadata/100/thumb/2', $out['sessions'][0]['posterPath']);
    }

    public function testMoviePosterUsesThumb(): void
    {
        $out = TautulliClient::normalizeActivity($this->fixtureData());
        self::assertSame('/library/metadata/12345/thumb/1700000000', $out['sessions'][0]['posterPath']);
    }

    /**
     * The image-proxy endpoint must only ever fetch Plex library image paths —
     * never an absolute URL, a scheme, a traversal, or a non-image Plex route —
     * so it can't be abused as an open relay/SSRF vector.
     */
    #[DataProvider('proxyablePaths')]
    public function testIsProxyableImagePath(string $img, bool $expected): void
    {
        self::assertSame($expected, TautulliClient::isProxyableImagePath($img));
    }

    public static function proxyablePaths(): array
    {
        return [
            'metadata thumb'        => ['/library/metadata/12345/thumb/1700000000', true],
            'grandparent thumb'     => ['/library/metadata/100/art/2', true],
            'empty'                 => ['', false],
            'absolute http url'     => ['http://169.254.169.254/latest/meta-data', false],
            'absolute https url'    => ['https://evil.example.com/x.jpg', false],
            'protocol-relative'     => ['//evil.example.com/x.jpg', false],
            'path traversal'        => ['/library/../../etc/passwd', false],
            'not a library path'    => ['/api/v2?cmd=get_settings', false],
            'query injection'       => ['/library/metadata/1/thumb/2?cmd=delete', false],
            'backslash'             => ['/library\\metadata/1', false],
        ];
    }

    /** A representative get_metadata `data` object for a movie. */
    private function metadataMovieFixture(): array
    {
        return [
            'rating_key'     => '12345',
            'media_type'     => 'movie',
            'title'          => 'See How They Run',
            'year'           => '2022',
            'summary'        => 'In 1950s London, plans for a movie adaptation grind to a halt.',
            'tagline'        => 'Watch your step.',
            'content_rating' => 'PG-13',
            'duration'       => '5820000',
            'rating'         => '7.5',
            'audience_rating'=> '8.1',
            'studio'         => 'Searchlight Pictures',
            'originally_available_at' => '2022-09-16',
            'genres'         => ['Comedy', 'Crime', 'Mystery'],
            'directors'      => ['Tom George'],
            'writers'        => ['Mark Chappell'],
            'actors'         => ['Sam Rockwell', 'Saoirse Ronan', 'Adrien Brody', 'Ruth Wilson',
                                 'Reece Shearsmith', 'Harris Dickinson', 'David Oyelowo', 'Pippa Bennett-Warner',
                                 'Extra Person Nine'],
            'thumb'          => '/library/metadata/12345/thumb/1700000000',
            'media_info'     => [[
                'container'            => 'mkv',
                'bitrate'              => '8700',
                'video_codec'          => 'hevc',
                'audio_codec'          => 'eac3',
                'video_full_resolution'=> '1080p',
            ]],
            // private fields that must be stripped:
            'file'           => '/data/media/movies/SeeHowTheyRun.mkv',
            'section_id'     => '1',
            'guid'           => 'plex://movie/abc',
            'live'           => 0,
        ];
    }

    public function testNormalizeMetadataMapsMovieFields(): void
    {
        $out = TautulliClient::normalizeMetadata($this->metadataMovieFixture());

        self::assertSame('movie', $out['mediaType']);
        self::assertSame('See How They Run', $out['title']);
        self::assertSame('2022', $out['year']);
        self::assertSame('PG-13', $out['contentRating']);
        self::assertSame('Searchlight Pictures', $out['studio']);
        self::assertSame(['Comedy', 'Crime', 'Mystery'], $out['genres']);
        self::assertSame(['Tom George'], $out['directors']);
        self::assertSame(7.5, $out['ratings']['critic']);
        self::assertSame(8.1, $out['ratings']['audience']);
        self::assertSame('1080p', $out['media']['resolution']);
        self::assertSame('hevc', $out['media']['videoCodec']);
        self::assertSame('mkv', $out['media']['container']);
        self::assertSame(8700, $out['media']['bitrateKbps']);
        self::assertSame('1 h 37 min', $out['durationLabel']);
    }

    public function testNormalizeMetadataCapsCastAtEight(): void
    {
        $out = TautulliClient::normalizeMetadata($this->metadataMovieFixture());
        self::assertCount(8, $out['cast']);
        self::assertSame('Sam Rockwell', $out['cast'][0]);
        self::assertNotContains('Extra Person Nine', $out['cast']);
    }

    public function testNormalizeMetadataMapsEpisodeFields(): void
    {
        $data = $this->metadataMovieFixture();
        $data['media_type']        = 'episode';
        $data['title']             = 'Ghost War';
        $data['grandparent_title'] = "Tom Clancy's Jack Ryan";
        $data['parent_media_index']= '1';
        $data['media_index']       = '3';
        $data['grandparent_thumb'] = '/library/metadata/100/thumb/2';

        $out = TautulliClient::normalizeMetadata($data);

        self::assertSame('episode', $out['mediaType']);
        self::assertSame("Tom Clancy's Jack Ryan", $out['grandparentTitle']);
        self::assertSame(1, $out['season']);
        self::assertSame(3, $out['episode']);
    }

    public function testNormalizeMetadataStripsPrivateFields(): void
    {
        $out = TautulliClient::normalizeMetadata($this->metadataMovieFixture());
        foreach (['file', 'section_id', 'guid', 'media_info'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $out, "private field {$forbidden} leaked");
        }
        $flat = json_encode($out);
        self::assertStringNotContainsString('/data/media/movies', $flat);
        self::assertStringNotContainsString('plex://', $flat);
    }

    public function testNormalizeMetadataDurationUnderOneHourAndZero(): void
    {
        // 2_700_000 ms = 45 min
        self::assertSame('45 min', TautulliClient::normalizeMetadata(['duration' => '2700000'])['durationLabel']);
        // zero / missing duration -> null label
        self::assertNull(TautulliClient::normalizeMetadata(['duration' => '0'])['durationLabel']);
        self::assertNull(TautulliClient::normalizeMetadata([])['durationLabel']);
    }

    public function testNormalizeMetadataRatingsAbsentVsZero(): void
    {
        // absent ratings -> null
        $out = TautulliClient::normalizeMetadata([]);
        self::assertNull($out['ratings']['critic']);
        self::assertNull($out['ratings']['audience']);
        // a genuine zero rating must survive as 0.0 (not collapse to null)
        $out = TautulliClient::normalizeMetadata(['rating' => '0', 'audience_rating' => '0']);
        self::assertSame(0.0, $out['ratings']['critic']);
        self::assertSame(0.0, $out['ratings']['audience']);
    }

    public function testNormalizeMetadataHandlesEmptyMediaInfo(): void
    {
        $out = TautulliClient::normalizeMetadata(['media_info' => []]);
        self::assertNull($out['media']['resolution']);
        self::assertNull($out['media']['videoCodec']);
        self::assertNull($out['media']['container']);
        self::assertSame(0, $out['media']['bitrateKbps']);
    }

    /** A representative get_history `data` envelope (Tautulli wraps rows in `data`). */
    private function historyFixture(): array
    {
        return [
            'recordsFiltered' => 2,
            'data' => [
                [
                    'rating_key'        => '12345',
                    'media_type'        => 'movie',
                    'full_title'        => 'See How They Run',
                    'title'             => 'See How They Run',
                    'year'              => '2022',
                    'thumb'             => '/library/metadata/12345/thumb/1',
                    'friendly_name'     => 'nDanDan',
                    'user'              => 'nDanDan',
                    'date'              => 1781377600,
                    'percent_complete'  => 96,
                    'watched_status'    => 1,
                    'ip_address'        => '192.168.1.5',
                    'user_id'           => 99,
                ],
                [
                    'rating_key'        => '777',
                    'media_type'        => 'episode',
                    'full_title'        => "Tom Clancy's Jack Ryan - Ghost War",
                    'title'             => 'Ghost War',
                    'grandparent_title' => "Tom Clancy's Jack Ryan",
                    'grandparent_thumb' => '/library/metadata/100/thumb/2',
                    'thumb'             => '/library/metadata/777/thumb/3',
                    'friendly_name'     => 'nDanDan',
                    'username'          => 'plexlogin_secret',
                    'date'              => 1781370000,
                    'percent_complete'  => 50,
                ],
            ],
        ];
    }

    public function testNormalizeHistoryMapsRows(): void
    {
        $out = TautulliClient::normalizeHistory($this->historyFixture());
        self::assertCount(2, $out);

        self::assertSame('12345', $out[0]['ratingKey']);
        self::assertSame('See How They Run', $out[0]['title']);
        self::assertSame('nDanDan', $out[0]['userDisplayName']);
        self::assertSame(1781377600, $out[0]['watchedAt']);
        self::assertSame(96, $out[0]['percentComplete']);
        self::assertSame('/library/metadata/12345/thumb/1', $out[0]['posterPath']);
    }

    public function testNormalizeHistoryEpisodePrefersGrandparentPoster(): void
    {
        $out = TautulliClient::normalizeHistory($this->historyFixture());
        self::assertSame('episode', $out[1]['mediaType']);
        self::assertSame("Tom Clancy's Jack Ryan", $out[1]['grandparentTitle']);
        self::assertSame('/library/metadata/100/thumb/2', $out[1]['posterPath']);
    }

    public function testNormalizeHistoryNeverLeaksPlexLogin(): void
    {
        $out = TautulliClient::normalizeHistory($this->historyFixture());
        $flat = json_encode($out);
        self::assertStringNotContainsString('plexlogin_secret', $flat);
        self::assertStringNotContainsString('192.168.1.5', $flat);
        foreach ($out as $row) {
            self::assertArrayNotHasKey('username', $row);
            self::assertArrayNotHasKey('ip_address', $row);
        }
    }
}
