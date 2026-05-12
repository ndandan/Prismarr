<?php

namespace App\Tests\Controller;

use App\Tests\AbstractWebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pre-1.1.0 media URLs (`/medias/films`, `/medias/series`, `/medias/radarr/...`,
 * `/medias/sonarr/...`) must keep working after the multi-instance move so that
 * bookmarks and any browser still showing a cached v1.0.x page don't 404.
 */
class LegacyMediaRedirectTest extends AbstractWebTestCase
{
    private function assertRedirectsTo(string $from, string $expectedLocation): void
    {
        $this->client->request('GET', $from);
        $res = $this->client->getResponse();
        $this->assertSame(Response::HTTP_TEMPORARY_REDIRECT, $res->getStatusCode(), "GET $from");
        $this->assertSame($expectedLocation, $res->headers->get('Location'), "GET $from");
    }

    public function testFilmsAndSeriesIndexesRedirectToTheDefaultInstance(): void
    {
        $this->assertRedirectsTo('/medias/films', '/medias/radarr-1/films');
        $this->assertRedirectsTo('/medias/series', '/medias/sonarr-1/series');
    }

    public function testLegacyAjaxAndSubpagesRedirectWithTheirSuffix(): void
    {
        $this->assertRedirectsTo('/medias/series/queue', '/medias/sonarr-1/series/queue');
        $this->assertRedirectsTo('/medias/films/warnings', '/medias/radarr-1/films/warnings');
        $this->assertRedirectsTo('/medias/radarr/mises-a-jour', '/medias/radarr-1/radarr/mises-a-jour');
        $this->assertRedirectsTo('/medias/sonarr/mises-a-jour', '/medias/sonarr-1/sonarr/mises-a-jour');
    }

    public function testRealSlugRouteIsNotInterceptedByTheFallback(): void
    {
        // /medias/radarr-1/films must hit MediaController, not the legacy
        // redirect — proves the priority:-100 fallback yields to real routes.
        $this->client->request('GET', '/medias/radarr-1/films');
        $this->assertNotSame(
            Response::HTTP_TEMPORARY_REDIRECT,
            $this->client->getResponse()->getStatusCode(),
        );
    }
}
