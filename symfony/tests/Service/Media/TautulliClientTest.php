<?php

namespace App\Tests\Service\Media;

use App\Service\Media\TautulliClient;
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
}
