<?php

namespace App\Tests\Service\Wokeometer;

use App\Repository\Media\WokeometerTitleRepository;
use App\Service\ConfigService;
use App\Service\Wokeometer\WokeometerLookup;
use App\Service\Wokeometer\WokeometerSettings;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Contracts\Service\ResetInterface;

#[AllowMockObjectsWithoutExpectations]
class WokeometerLookupTest extends TestCase
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    private array $logs = [];

    private function logger(): AbstractLogger
    {
        $logs = &$this->logs;
        return new class($logs) extends AbstractLogger {
            /** @param list<array{level: mixed, message: string, context: array<mixed>}> $logs */
            public function __construct(private array &$logs) {}

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->logs[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }

    private function settings(bool $enabled): WokeometerSettings
    {
        $values = $enabled ? ['wokeometer_api_key' => 'wok_0123456789abcdef'] : [];
        $c = $this->createMock(ConfigService::class);
        $c->method('get')->willReturnCallback(fn(string $k) => $values[$k] ?? null);
        return new WokeometerSettings($c);
    }

    /** @return array<string, mixed> */
    private function dbRow(array $overrides = []): array
    {
        return array_merge([
            'id'                    => 1,
            'wokeometer_id'         => 'uuid-1',
            'media_type'            => 'movie',
            'tmdb_id'               => 603,
            'external_source'       => 'tmdb',
            'external_id'           => '603',
            'parent_wokeometer_id'  => null,
            'season_number'         => null,
            'title'                 => 'The Matrix',
            'release_date'          => '1999-03-31',
            'woke_score'            => 0,
            'tldr'                  => 'A summary.',
            'slug'                  => 'the-matrix',
            'is_analyzed'           => true,
            'wokeometer_updated_at' => 1_700_000_000,
            'last_seen_at'          => 1_700_000_100,
            'synced_at'             => 1_700_000_200,
        ], $overrides);
    }

    public function testImplementsResetInterface(): void
    {
        $this->assertTrue(is_subclass_of(WokeometerLookup::class, ResetInterface::class));
    }

    public function testDisabledReturnsNullWithoutTouchingRepository(): void
    {
        $repo = $this->createMock(WokeometerTitleRepository::class);
        $repo->expects($this->never())->method('findForTmdb');
        $repo->expects($this->never())->method('findForTmdbMany');

        $lookup = new WokeometerLookup($this->settings(false), $repo, $this->logger());

        $this->assertNull($lookup->forTmdb('movie', 603));
        $this->assertSame([], $lookup->forTmdbMany('movie', [603]));
    }

    public function testMatchReturnsViewShape(): void
    {
        $repo = $this->createMock(WokeometerTitleRepository::class);
        $repo->expects($this->once())->method('findForTmdb')->with('movie', 603)->willReturn($this->dbRow());

        $lookup = new WokeometerLookup($this->settings(true), $repo, $this->logger());

        $this->assertSame([
            'score'     => 0,
            'tldr'      => 'A summary.',
            'url'       => 'https://wokeometer.app/media/movie/the-matrix',
            'analyzed'  => true,
            'title'     => 'The Matrix',
            'updatedAt' => 1_700_000_000,
        ], $lookup->forTmdb('movie', 603));
    }

    public function testTldrMarkdownEmphasisIsStrippedForDisplay(): void
    {
        $repo = $this->createMock(WokeometerTitleRepository::class);
        $repo->method('findForTmdb')->willReturn($this->dbRow([
            'tldr' => "**The Matrix**: Pure spectacle\r\nand *style*, with _some_ __bold__ claims and **zero woke preaching** (2/10)\n\n",
        ]));

        $view = (new WokeometerLookup($this->settings(true), $repo, $this->logger()))->forTmdb('movie', 603);

        $this->assertNotNull($view);
        $this->assertSame(
            'The Matrix: Pure spectacle and style, with some bold claims and zero woke preaching (2/10)',
            $view['tldr'],
        );
        $this->assertStringNotContainsString('*', $view['tldr']);
    }

    public function testTldrIntraWordUnderscoresAndApostrophesAreLeftAlone(): void
    {
        $repo = $this->createMock(WokeometerTitleRepository::class);
        $repo->method('findForTmdb')->willReturn($this->dbRow([
            'tldr' => "It's about snake_case_name and don't_touch_this, plus 2 * 3 math.",
        ]));

        $view = (new WokeometerLookup($this->settings(true), $repo, $this->logger()))->forTmdb('movie', 603);

        $this->assertNotNull($view);
        $this->assertSame("It's about snake_case_name and don't_touch_this, plus 2 * 3 math.", $view['tldr']);
    }

    public function testUnsafeSlugYieldsNullUrl(): void
    {
        $repo = $this->createMock(WokeometerTitleRepository::class);
        $repo->method('findForTmdb')->willReturn($this->dbRow(['slug' => '../x', 'media_type' => 'tv']));

        $view = (new WokeometerLookup($this->settings(true), $repo, $this->logger()))->forTmdb('tv', 603);

        $this->assertNotNull($view);
        $this->assertNull($view['url']);
    }

    public function testARowWithNeitherScoreNorTldrIsHidden(): void
    {
        $empty = $this->dbRow(['woke_score' => null, 'tldr' => null]);
        $blank = $this->dbRow(['wokeometer_id' => 'uuid-2', 'tmdb_id' => 604, 'woke_score' => null, 'tldr' => '']);
        $repo  = $this->createMock(WokeometerTitleRepository::class);
        $repo->method('findForTmdb')->willReturn($empty);
        $repo->method('findForTmdbMany')->willReturn([603 => $empty, 604 => $blank, 605 => $this->dbRow(['tmdb_id' => 605, 'tldr' => null])]);

        $lookup = new WokeometerLookup($this->settings(true), $repo, $this->logger());
        $this->assertNull($lookup->forTmdb('movie', 603), 'a slug alone is no content: no bare "view full analysis" link');
        $lookup->reset();
        $this->assertSame([605], array_keys($lookup->forTmdbMany('movie', [603, 604, 605])), 'score 0 without TL;DR still shows');

        $lookup->reset();
        $repo2 = $this->createMock(WokeometerTitleRepository::class);
        $repo2->method('findForTmdb')->willReturn($this->dbRow(['woke_score' => null, 'tldr' => 'Only a summary.']));
        $view = (new WokeometerLookup($this->settings(true), $repo2, $this->logger()))->forTmdb('movie', 603);
        $this->assertNotNull($view, 'a TL;DR alone is content');
        $this->assertNull($view['score']);
    }

    public function testNoMatchReturnsNull(): void
    {
        $repo = $this->createMock(WokeometerTitleRepository::class);
        $repo->method('findForTmdb')->willReturn(null);

        $this->assertNull((new WokeometerLookup($this->settings(true), $repo, $this->logger()))->forTmdb('movie', 1));
    }

    public function testInvalidInputShortCircuits(): void
    {
        $repo = $this->createMock(WokeometerTitleRepository::class);
        $repo->expects($this->never())->method('findForTmdb');

        $lookup = new WokeometerLookup($this->settings(true), $repo, $this->logger());
        $this->assertNull($lookup->forTmdb('series', 603));
        $this->assertNull($lookup->forTmdb('movie', 0));
        $this->assertNull($lookup->forTmdb('movie', -5));
    }

    public function testMemoHitAvoidsSecondQueryIncludingMisses(): void
    {
        $repo = $this->createMock(WokeometerTitleRepository::class);
        $repo->expects($this->exactly(2))->method('findForTmdb')
            ->willReturnCallback(fn(string $t, int $id) => $id === 603 ? $this->dbRow() : null);

        $lookup = new WokeometerLookup($this->settings(true), $repo, $this->logger());

        $first = $lookup->forTmdb('movie', 603);
        $this->assertSame($first, $lookup->forTmdb('movie', 603));
        $this->assertNull($lookup->forTmdb('movie', 1));
        $this->assertNull($lookup->forTmdb('movie', 1));
    }

    public function testResetClearsMemo(): void
    {
        $repo = $this->createMock(WokeometerTitleRepository::class);
        $repo->expects($this->exactly(2))->method('findForTmdb')->willReturn($this->dbRow());

        $lookup = new WokeometerLookup($this->settings(true), $repo, $this->logger());
        $lookup->forTmdb('movie', 603);
        $lookup->reset();
        $lookup->forTmdb('movie', 603);
    }

    public function testRepositoryFailureReturnsNullAndWarnsOncePerRequest(): void
    {
        $repo = $this->createMock(WokeometerTitleRepository::class);
        $repo->method('findForTmdb')->willThrowException(new \RuntimeException('no such table: wokeometer_media'));
        $repo->method('findForTmdbMany')->willThrowException(new \RuntimeException('no such table: wokeometer_media'));

        $lookup = new WokeometerLookup($this->settings(true), $repo, $this->logger());

        $this->assertNull($lookup->forTmdb('movie', 603));
        $this->assertNull($lookup->forTmdb('movie', 604));
        $this->assertNull($lookup->forTmdb('tv', 605));
        $this->assertSame([], $lookup->forTmdbMany('movie', [1, 2]));

        $this->assertCount(1, $this->logs, 'exactly one warning per request');
        $this->assertSame('warning', $this->logs[0]['level']);

        // Next request (worker mode): a new warning is allowed.
        $lookup->reset();
        $lookup->forTmdb('movie', 603);
        $this->assertCount(2, $this->logs);
    }

    public function testForTmdbManyUsesMemoAndReturnsOnlyMatches(): void
    {
        $repo = $this->createMock(WokeometerTitleRepository::class);
        $repo->method('findForTmdb')->willReturn($this->dbRow());
        $repo->expects($this->once())->method('findForTmdbMany')
            ->with('movie', [10, 11])
            ->willReturn([10 => $this->dbRow(['tmdb_id' => 10, 'woke_score' => 3])]);

        $lookup = new WokeometerLookup($this->settings(true), $repo, $this->logger());
        $lookup->forTmdb('movie', 603); // memoised

        $many = $lookup->forTmdbMany('movie', [603, 10, 11, 10, 0]);

        $this->assertSame([603, 10], array_keys($many));
        $this->assertSame(3, $many[10]['score']);
        $this->assertSame(0, $many[603]['score']);

        // All three ids now memoised (11 as a miss) → no further repository call.
        $this->assertNull($lookup->forTmdb('movie', 11));
        $this->assertSame(3, $lookup->forTmdb('movie', 10)['score'] ?? null);
        $this->assertSame([10], array_keys($lookup->forTmdbMany('movie', [10, 11])));
    }
}
