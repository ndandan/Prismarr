<?php

namespace App\Tests\Controller;

use App\Tests\AbstractWebTestCase;

/**
 * Smoke tests for the Radarr/Sonarr library pages after the caching +
 * concurrent-fetch rework.
 *
 * AbstractWebTestCase seeds default radarr-1 / sonarr-1 instances pointing
 * at unreachable hosts, so every request exercises the multiGet error path:
 * the concurrent batch fails to connect, status comes back null, the
 * controller sets $error = true and renders the error banner. The contract
 * we lock here is "renders cleanly (200), never 500s, never hangs" — the
 * exact behavior users hit when their *arr is briefly down.
 */
class MediaLibraryPageTest extends AbstractWebTestCase
{
    public function testFilmsPageRendersWhenRadarrUnreachable(): void
    {
        $this->client->request('GET', '/medias/radarr-1/films');

        self::assertResponseIsSuccessful();
    }

    public function testSeriesPageRendersWhenSonarrUnreachable(): void
    {
        $this->client->request('GET', '/medias/sonarr-1/series');

        self::assertResponseIsSuccessful();
    }
}
