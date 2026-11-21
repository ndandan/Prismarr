<?php

namespace App\Tests\Controller;

use App\Controller\BazarrController;
use App\Entity\ServiceInstance;
use App\Service\ConfigService;
use App\Service\Media\BazarrClient;
use App\Service\Media\BazarrPosterResolver;
use App\Service\Media\BazarrSubtitleIndex;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;

/**
 * Bazarr Sync / Translate / Extract from the subtitle modal (batch 2):
 *  - GET /bazarr/api/files/{kind}/{id} lists the item's current subtitles
 *    (with path / database id) and its missing languages;
 *  - POST /bazarr/api/subtitle-action validates and forwards one action to
 *    Bazarr's PATCH /api/subtitles, mapping Bazarr's refusals to a reason
 *    the modal can translate.
 * Controller built directly (see BazarrSubtitlesApiTest for why the
 * container's BazarrClient can't be swapped after setUp).
 */
#[AllowMockObjectsWithoutExpectations]
class BazarrSubtitleActionTest extends TestCase
{
    private function controller(BazarrClient $client, bool $oneInstance = true): BazarrController
    {
        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->method('hasExactlyOneEnabled')->willReturn($oneInstance);

        $controller = new BazarrController(
            $client,
            $this->createMock(ConfigService::class),
            new NullLogger(),
            $this->createMock(BazarrSubtitleIndex::class),
            $instances,
            $this->createMock(BazarrPosterResolver::class),
        );
        $controller->setContainer(new Container());

        return $controller;
    }

    /** @return array<string, mixed> */
    private static function json(\Symfony\Component\HttpFoundation\Response $r): array
    {
        return json_decode((string) $r->getContent(), true);
    }

    /** @return array<string, mixed> */
    private static function movie(): array
    {
        return [
            'radarrId'  => 5,
            'subtitles' => [
                ['name' => 'English', 'code2' => 'en', 'path' => '/movies/X/x.en.srt', 'forced' => false, 'hi' => false, 'id' => 3, 'embedded_track_id' => null],
                ['name' => 'French', 'code2' => 'fr', 'path' => null, 'forced' => false, 'hi' => true, 'id' => 4, 'embedded_track_id' => 2],
                ['name' => 'no code'],
            ],
            'missing_subtitles' => [['name' => 'German', 'code2' => 'de', 'forced' => false, 'hi' => false]],
        ];
    }

    // ── GET /api/files ──────────────────────────────────────────────────────

    public function testMovieFilesListExternalAndEmbeddedSubtitles(): void
    {
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->once())->method('getMovies')->with([5])->willReturn([self::movie()]);

        $data = self::json($this->controller($client)->apiFiles('movie', 5, Request::create('/')));

        self::assertTrue($data['tracked']);
        self::assertSame([
            ['name' => 'English', 'code2' => 'en', 'path' => '/movies/X/x.en.srt', 'forced' => false, 'hi' => false, 'id' => 3, 'embedded' => false],
            ['name' => 'French', 'code2' => 'fr', 'path' => null, 'forced' => false, 'hi' => true, 'id' => 4, 'embedded' => true],
        ], $data['subtitles']);
        self::assertSame([['name' => 'German', 'code2' => 'de', 'forced' => false, 'hi' => false]], $data['missing']);
    }

    public function testPreBazarr16SubtitlesHaveNoId(): void
    {
        $movie = self::movie();
        unset($movie['subtitles'][0]['id'], $movie['subtitles'][1]['id']);
        $client = $this->createMock(BazarrClient::class);
        $client->method('getMovies')->willReturn([$movie]);

        $data = self::json($this->controller($client)->apiFiles('movie', 5, Request::create('/')));

        self::assertNull($data['subtitles'][0]['id']);
        self::assertNull($data['subtitles'][1]['id']);
    }

    public function testEpisodeFilesPickTheRequestedEpisode(): void
    {
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->once())->method('getEpisodes')->with(7)->willReturn([
            ['sonarrEpisodeId' => 70, 'subtitles' => [['name' => 'Spanish', 'code2' => 'es', 'path' => '/tv/e70.es.srt']], 'missing_subtitles' => []],
            ['sonarrEpisodeId' => 71, 'subtitles' => [['name' => 'English', 'code2' => 'en', 'path' => '/tv/e71.en.srt']], 'missing_subtitles' => []],
        ]);

        $data = self::json($this->controller($client)->apiFiles('episode', 71, Request::create('/', 'GET', ['seriesId' => '7'])));

        self::assertTrue($data['tracked']);
        self::assertSame('/tv/e71.en.srt', $data['subtitles'][0]['path']);
    }

    public function testTwoInstancesMakeBareIdsUntracked(): void
    {
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->never())->method('getMovies');

        $data = self::json($this->controller($client, false)->apiFiles('movie', 5, Request::create('/')));

        self::assertFalse($data['tracked']);
        self::assertSame([], $data['subtitles']);
    }

    public function testAnEpisodeWithoutItsSeriesIdIsUntracked(): void
    {
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->never())->method('getEpisodes');

        self::assertFalse(self::json($this->controller($client)->apiFiles('episode', 71, Request::create('/')))['tracked']);
    }

    public function testAnAbsentMovieIsUntracked(): void
    {
        $client = $this->createMock(BazarrClient::class);
        $client->method('getMovies')->willReturn([]);

        self::assertFalse(self::json($this->controller($client)->apiFiles('movie', 5, Request::create('/')))['tracked']);
    }

    // ── POST /api/subtitle-action ───────────────────────────────────────────

    private static function post(array $fields): Request
    {
        return Request::create('/bazarr/api/subtitle-action', 'POST', $fields);
    }

    public function testSyncIsForwardedToBazarr(): void
    {
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->once())->method('subtitleAction')
            ->with('sync', 'movie', 5, 'en', '/movies/X/x.en.srt', null, false, true)
            ->willReturn(true);

        $r = $this->controller($client)->apiSubtitleAction(self::post([
            'action' => 'sync', 'type' => 'movie', 'id' => '5', 'language' => 'en', 'path' => '/movies/X/x.en.srt', 'forced' => '0', 'hi' => '1',
        ]));

        self::assertSame(200, $r->getStatusCode());
        self::assertSame(['ok' => true], self::json($r));
    }

    public function testTranslateFromAnEmbeddedTrack(): void
    {
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->once())->method('subtitleAction')
            ->with('translate', 'episode', 70, 'pt-BR', null, 12, false, false)
            ->willReturn(true);

        $this->controller($client)->apiSubtitleAction(self::post([
            'action' => 'translate', 'type' => 'episode', 'id' => '70', 'language' => 'pt-BR', 'subtitles_id' => '12',
        ]));
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function invalidRequests(): iterable
    {
        $ok = ['action' => 'sync', 'type' => 'movie', 'id' => '5', 'language' => 'en', 'path' => '/x.srt'];
        yield 'unknown action'       => [['action' => 'remove_HI'] + $ok];
        yield 'unknown type'         => [['type' => 'series'] + $ok];
        yield 'zero id'              => [['id' => '0'] + $ok];
        yield 'bad language'         => [['language' => 'e n'] + $ok];
        yield 'sync without path'    => [['path' => ''] + $ok];
        yield 'extract without id'   => [['action' => 'extract'] + $ok];
        yield 'translate no source'  => [['action' => 'translate', 'path' => ''] + $ok];
    }

    /** @param array<string, string> $fields */
    #[DataProvider('invalidRequests')]
    public function testInvalidRequestsNeverReachBazarr(array $fields): void
    {
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->never())->method('subtitleAction');

        $r = $this->controller($client)->apiSubtitleAction(self::post($fields));

        self::assertSame(400, $r->getStatusCode());
        self::assertFalse(self::json($r)['ok']);
    }

    /** @return iterable<string, array{int, string}> */
    public static function refusals(): iterable
    {
        yield 'image-based track'  => [415, 'image_based'];
        yield 'file error'         => [409, 'file_error'];
        yield 'path mapping'       => [500, 'path_mapping'];
        yield 'translation failed' => [502, 'translate_failed'];
    }

    #[DataProvider('refusals')]
    public function testBazarrRefusalsCarryAReason(int $code, string $reason): void
    {
        $client = $this->createMock(BazarrClient::class);
        $client->method('subtitleAction')->willReturn(false);
        $client->method('getLastError')->willReturn(['code' => $code, 'method' => 'PATCH', 'path' => '/subtitles', 'message' => 'unexpected HTTP status']);

        $r = $this->controller($client)->apiSubtitleAction(self::post([
            'action' => 'extract', 'type' => 'movie', 'id' => '5', 'language' => 'en', 'subtitles_id' => '3',
        ]));

        self::assertSame(502, $r->getStatusCode());
        self::assertSame(['ok' => false, 'reason' => $reason], self::json($r));
    }

    /**
     * Review 2026-11-21 M1: Bazarr 1.5 answers only when the sync is done. A
     * sync that outlasts the budget is still running there — calling it a
     * failure invites a second click, and a second sync of the same file.
     */
    public function testAnActionThatOutlastsTheBudgetIsReportedAsStillRunning(): void
    {
        $client = $this->createMock(BazarrClient::class);
        $client->method('subtitleAction')->willReturn(false);
        $client->method('getLastError')->willReturn(['code' => 0, 'method' => 'PATCH', 'path' => '/subtitles', 'message' => 'Operation timed out']);
        $client->method('lastErrorTimedOut')->willReturn(true);

        $r = $this->controller($client)->apiSubtitleAction(self::post([
            'action' => 'sync', 'type' => 'movie', 'id' => '5', 'language' => 'en', 'path' => '/x.srt',
        ]));

        self::assertSame(504, $r->getStatusCode());
        self::assertSame(['ok' => false, 'reason' => 'still_running'], self::json($r));
    }

    public function testAnUnreachableBazarrFallsBackToTheClientError(): void
    {
        $client = $this->createMock(BazarrClient::class);
        $client->method('subtitleAction')->willReturn(false);
        $client->method('getLastError')->willReturn(['code' => 0, 'method' => 'PATCH', 'path' => '/subtitles', 'message' => 'connection failed']);

        $r = $this->controller($client)->apiSubtitleAction(self::post([
            'action' => 'sync', 'type' => 'movie', 'id' => '5', 'language' => 'en', 'path' => '/x.srt',
        ]));

        self::assertSame(500, $r->getStatusCode());
        self::assertFalse(self::json($r)['ok']);
        self::assertArrayNotHasKey('reason', self::json($r));
    }
}
