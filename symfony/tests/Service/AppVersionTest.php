<?php

namespace App\Tests\Service;

use App\Service\AppVersion;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class AppVersionTest extends TestCase
{
    public function testCurrentReturnsConstant(): void
    {
        $svc = new AppVersion($this->emptyCache(), new NullLogger());
        $this->assertSame(AppVersion::VERSION, $svc->current());
    }

    public function testRuntimeVersionFromEnvOverridesConstant(): void
    {
        $svc = new AppVersion($this->emptyCache(), new NullLogger(), '1.1.0-beta.3');
        $this->assertSame('1.1.0-beta.3', $svc->current());
    }

    public function testRuntimeVersionFallsBackOnEmptyOrDevPlaceholder(): void
    {
        $this->assertSame(AppVersion::VERSION, (new AppVersion($this->emptyCache(), new NullLogger(), ''))->current());
        $this->assertSame(AppVersion::VERSION, (new AppVersion($this->emptyCache(), new NullLogger(), 'dev'))->current());
    }

    public function testReleasesReadsFromCacheWhenHit(): void
    {
        $cached = [
            ['tag' => '1.2.3', 'name' => 'v1.2.3', 'body' => 'changes', 'published_at' => '2026-04-26T10:00:00Z', 'html_url' => 'https://example/1.2.3'],
        ];

        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $item->method('get')->willReturn($cached);

        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn($item);

        $svc = new AppVersion($pool, new NullLogger());
        $this->assertSame($cached, $svc->releases());
    }

    public function testLatestReturnsFirstTagFromCache(): void
    {
        $cached = [
            ['tag' => '9.9.9', 'name' => 'v9.9.9', 'body' => '', 'published_at' => '', 'html_url' => ''],
            ['tag' => '0.0.1', 'name' => 'v0.0.1', 'body' => '', 'published_at' => '', 'html_url' => ''],
        ];
        $svc = $this->withCachedReleases($cached);
        $this->assertSame('9.9.9', $svc->latest());
    }

    public function testUpstreamReleasesNoLongerDriveIsUpdateAvailable(): void
    {
        $releases = [['tag' => '99.0.0', 'name' => 'v99', 'body' => '', 'body_html' => '', 'published_at' => '', 'html_url' => '']];
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $item->method('get')->willReturn($releases);
        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn($item);
        $v = new AppVersion($pool, new NullLogger(), '1.2.3'); // no SHA → behind unknown
        self::assertFalse($v->isUpdateAvailable());
    }

    public function testRenderBodyEmptyReturnsEmpty(): void
    {
        $this->assertSame('', AppVersion::renderBody(''));
    }

    public function testRenderBodyEscapesHtml(): void
    {
        $html = AppVersion::renderBody('<script>alert(1)</script>');
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testRenderBodyHandlesBoldItalicCode(): void
    {
        $html = AppVersion::renderBody('plain **bold** *italic* `code` end');
        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<em>italic</em>', $html);
        $this->assertStringContainsString('<code', $html);
        $this->assertStringContainsString('>code</code>', $html);
    }

    public function testRenderBodyHandlesHeadingsAndLists(): void
    {
        $html = AppVersion::renderBody("## Added\n- one item\n- two\n\n### Changed\n- three");
        $this->assertStringContainsString('<h5', $html);
        $this->assertStringContainsString('Added</h5>', $html);
        $this->assertStringContainsString('<h6', $html);
        $this->assertStringContainsString('Changed</h6>', $html);
        $this->assertStringContainsString('<ul', $html);
        $this->assertStringContainsString('<li>one item</li>', $html);
        $this->assertStringContainsString('<li>three</li>', $html);
    }

    public function testRenderBodyAllowsHttpsLinksOnly(): void
    {
        $html = AppVersion::renderBody('See [docs](https://example.org/page) here');
        $this->assertStringContainsString('<a href="https://example.org/page" target="_blank" rel="noopener">docs</a>', $html);

        // javascript: must never produce an <a> tag — it falls through as escaped text.
        $jsAttempt = AppVersion::renderBody('Click [me](javascript:alert(1)) please');
        $this->assertStringNotContainsString('<a ', $jsAttempt);
        $this->assertStringNotContainsString('href=', $jsAttempt);
    }

    public function testResetClearsInProcessCache(): void
    {
        $svc = $this->withCachedReleases([
            ['tag' => '1.0.0', 'name' => '', 'body' => '', 'published_at' => '', 'html_url' => ''],
        ]);
        $this->assertSame('1.0.0', $svc->latest());
        $svc->reset();
        // Should not throw — pool still returns the same cached payload.
        $this->assertSame('1.0.0', $svc->latest());
    }

    public function testParseComparePayloadReadsAheadByAndCommits(): void
    {
        $payload = [
            'ahead_by' => 3,
            'commits'  => [
                ['sha' => str_repeat('a', 40), 'html_url' => 'https://x/a', 'commit' => ['message' => "first subject\n\nbody", 'committer' => ['date' => '2026-08-01T10:00:00Z']]],
                ['sha' => str_repeat('b', 40), 'html_url' => 'https://x/b', 'commit' => ['message' => 'second subject', 'committer' => ['date' => '2026-08-01T11:00:00Z']]],
            ],
        ];
        $parsed = AppVersion::parseComparePayload($payload);
        self::assertNotNull($parsed);
        self::assertSame(3, $parsed['behind']);
        // Newest first: the API lists commits oldest→newest, so order reverses.
        self::assertSame('bbbbbbb', $parsed['commits'][0]['sha7']);
        self::assertSame('second subject', $parsed['commits'][0]['subject']);
        self::assertSame('first subject', $parsed['commits'][1]['subject']);
        self::assertSame('https://x/a', $parsed['commits'][1]['html_url']);
    }

    public function testParseComparePayloadCapsAtFifteenCommits(): void
    {
        $commits = [];
        for ($i = 0; $i < 40; $i++) {
            $commits[] = ['sha' => sprintf('%040d', $i), 'html_url' => 'https://x/' . $i, 'commit' => ['message' => 'c' . $i, 'committer' => ['date' => '']]];
        }
        $parsed = AppVersion::parseComparePayload(['ahead_by' => 40, 'commits' => $commits]);
        self::assertNotNull($parsed);
        self::assertCount(15, $parsed['commits']);
        self::assertSame('c39', $parsed['commits'][0]['subject']); // newest kept
    }

    public function testParseComparePayloadRejectsGarbage(): void
    {
        self::assertNull(AppVersion::parseComparePayload(null));
        self::assertNull(AppVersion::parseComparePayload('nope'));
        self::assertNull(AppVersion::parseComparePayload(['commits' => []])); // no ahead_by
    }

    public function testSliceChangelogKeepsUnreleasedPlusTwoSections(): void
    {
        $md = "# Changelog\n\nintro\n\n## [Unreleased]\n\n- a\n\n## [1.1.0]\n\n- b\n\n## [1.0.0]\n\n- c\n\n## [0.9.0]\n\n- d\n";
        $sliced = AppVersion::sliceChangelog($md);
        self::assertStringContainsString('[Unreleased]', $sliced);
        self::assertStringContainsString('[1.0.0]', $sliced);
        self::assertStringNotContainsString('[0.9.0]', $sliced);
        self::assertStringNotContainsString('# Changelog', $sliced); // prelude dropped
    }

    public function testSliceChangelogWithFewerSectionsKeepsWhatExists(): void
    {
        $md = "## [Unreleased]\n\n- a\n\n## [1.0.0]\n\n- b\n";
        self::assertSame($md, AppVersion::sliceChangelog($md));
    }

    public function testSliceChangelogWithoutSectionsReturnsInputUnchanged(): void
    {
        self::assertSame("just text\n", AppVersion::sliceChangelog("just text\n"));
    }

    public function testCommitsBehindIsNullWithoutBuiltSha(): void
    {
        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->expects(self::never())->method('getItem'); // no SHA → no fetch, no cache
        $v = new AppVersion($pool, new NullLogger(), '1.2.3', '');
        self::assertNull($v->commitsBehind());
        self::assertSame([], $v->recentForkCommits());
        self::assertFalse($v->isUpdateAvailable());
        self::assertNull($v->builtSha());
        self::assertNull($v->builtShaShort());
    }

    public function testCommitsBehindReadsFromCacheWhenHit(): void
    {
        $cached = ['behind' => 5, 'commits' => [['sha7' => 'abc1234', 'subject' => 's', 'date' => '', 'html_url' => 'https://x']]];
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $item->method('get')->willReturn($cached);
        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn($item);
        $v = new AppVersion($pool, new NullLogger(), '1.2.3', str_repeat('a', 40));
        self::assertSame(5, $v->commitsBehind());
        self::assertTrue($v->isUpdateAvailable());
        self::assertSame('abc1234', $v->recentForkCommits()[0]['sha7']);
        self::assertSame('aaaaaaa', $v->builtShaShort());
    }

    public function testZeroBehindMeansUpToDate(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $item->method('get')->willReturn(['behind' => 0, 'commits' => []]);
        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn($item);
        $v = new AppVersion($pool, new NullLogger(), '1.2.3', str_repeat('a', 40));
        self::assertSame(0, $v->commitsBehind());
        self::assertFalse($v->isUpdateAvailable());
    }

    public function testUpstreamExposesFirstReleaseOrNull(): void
    {
        $releases = [['tag' => '1.1.1', 'name' => 'v1.1.1', 'body' => '', 'body_html' => '', 'published_at' => '2026-06-10T00:00:00Z', 'html_url' => 'https://gh/rel']];
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $item->method('get')->willReturn($releases);
        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn($item);
        $v = new AppVersion($pool, new NullLogger(), '1.2.3');
        self::assertSame(['tag' => '1.1.1', 'published_at' => '2026-06-10T00:00:00Z', 'html_url' => 'https://gh/rel'], $v->upstream());
    }

    /**
     * @param list<array{tag:string,name:string,body:string,published_at:string,html_url:string}> $cached
     */
    private function withCachedReleases(array $cached, string $runtimeVersion = ''): AppVersion
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $item->method('get')->willReturn($cached);

        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn($item);

        return new AppVersion($pool, new NullLogger(), $runtimeVersion);
    }

    private function emptyCache(): CacheItemPoolInterface
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);

        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn($item);

        return $pool;
    }
}
