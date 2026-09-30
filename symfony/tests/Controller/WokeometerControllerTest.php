<?php

namespace App\Tests\Controller;

use App\Entity\Media\WokeometerTitle;
use App\Entity\Setting;
use App\Tests\AbstractWebTestCase;

/**
 * The local, fail-closed lookup endpoint the Films/Series modals fetch on
 * open. Contract (fixed — Task 5 consumes it): always HTTP 200
 * `{"ok": true, "match": null | {score, tldr, url, analyzed, title, updatedAt}}`.
 */
class WokeometerControllerTest extends AbstractWebTestCase
{
    private function seedTitle(string $type = 'movie', int $tmdbId = 603, ?int $score = 3, ?string $tldr = 'A short summary.'): void
    {
        $em = $this->em();
        $t  = new WokeometerTitle('uuid-' . $type . '-' . $tmdbId, $type, 'The Matrix', 1_700_000_000, 1_700_000_100, 1_700_000_200);
        $t->setTmdbId($tmdbId)
            ->setExternalSource('tmdb')
            ->setExternalId((string) $tmdbId)
            ->setWokeScore($score)
            ->setTldr($tldr)
            ->setSlug('the-matrix')
            ->setAnalyzed(true);
        $em->persist($t);
        $em->flush();
    }

    private function seedEnabled(bool $enabled = true): void
    {
        $em = $this->em();
        if ($enabled) {
            $em->persist(new Setting('wokeometer_api_key', 'wok_testkey0123456789'));
        }
        $em->flush();
    }

    /** @return array<string, mixed> */
    private function lookup(string $path): array
    {
        $this->client->request('GET', $path);
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);

        return $data;
    }

    public function testMatchReturnsTheLocalRowWithPrivateShortCaching(): void
    {
        $this->seedEnabled();
        $this->seedTitle();

        $data = $this->lookup('/wokeometer/api/lookup/movie/603');

        self::assertTrue($data['ok']);
        self::assertSame(3, $data['match']['score']);
        self::assertSame('A short summary.', $data['match']['tldr']);
        self::assertSame('https://wokeometer.app/media/movie/the-matrix', $data['match']['url']);
        self::assertSame('The Matrix', $data['match']['title']);

        $cc = (string) $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cc);
        self::assertStringContainsString('max-age=60', $cc);
    }

    public function testTvTypeUsesTheTvRows(): void
    {
        $this->seedEnabled();
        $this->seedTitle('tv', 95396, 8);

        $data = $this->lookup('/wokeometer/api/lookup/tv/95396');
        self::assertSame(8, $data['match']['score']);

        // The same id under the other vocabulary is a different (absent) row.
        $data = $this->lookup('/wokeometer/api/lookup/movie/95396');
        self::assertNull($data['match']);
    }

    public function testNoRowIsAnOkNullMatch(): void
    {
        $this->seedEnabled();

        $data = $this->lookup('/wokeometer/api/lookup/movie/999999');

        self::assertTrue($data['ok']);
        self::assertNull($data['match']);
    }

    public function testDisabledIntegrationHidesAnExistingRow(): void
    {
        // Row present, but no API key configured => integration off => null.
        $this->seedEnabled(false);
        $this->seedTitle();

        $data = $this->lookup('/wokeometer/api/lookup/movie/603');

        self::assertTrue($data['ok']);
        self::assertNull($data['match']);
    }

    public function testExplicitlyDisabledFlagHidesAnExistingRow(): void
    {
        $this->seedEnabled();
        $this->em()->persist(new Setting('wokeometer_enabled', '0'));
        $this->em()->flush();
        $this->seedTitle();

        $data = $this->lookup('/wokeometer/api/lookup/movie/603');

        self::assertNull($data['match']);
    }

    public function testUnknownTypeIsRejectedByRouting(): void
    {
        $this->client->request('GET', '/wokeometer/api/lookup/series/603');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testNonNumericIdIsRejectedByRouting(): void
    {
        $this->client->request('GET', '/wokeometer/api/lookup/movie/abc');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testEndpointRequiresAnAuthenticatedUser(): void
    {
        static::ensureKernelShutdown();
        $anon = static::createClient();
        $anon->request('GET', '/wokeometer/api/lookup/movie/603');

        $response = $anon->getResponse();
        self::assertNotSame(200, $response->getStatusCode(), 'the lookup must not be public');
        self::assertTrue($response->isRedirect(), 'anonymous callers are sent to login / setup');
    }

    public function testDetailViewPathNeverIssuesAnApiRequest(): void
    {
        // "No API request on detail view": the whole read path (controller,
        // lookup, repositories) is local SQLite only. Network I/O may live
        // solely in the sync client behind WokeometerSyncService::runChunk().
        $root = __DIR__ . '/../../src/';
        foreach ([
            'Controller/WokeometerController.php',
            'Service/Wokeometer/WokeometerLookup.php',
            'Repository/Media/WokeometerTitleRepository.php',
            'Repository/Media/WokeometerSyncStateRepository.php',
        ] as $rel) {
            $src = file_get_contents($root . $rel);
            self::assertNotFalse($src, $rel . ' must exist');
            foreach (['curl_', 'HttpClient', 'file_get_contents(', "fopen('http"] as $needle) {
                self::assertStringNotContainsString($needle, $src, $rel . ' must not perform network I/O (' . $needle . ')');
            }
        }
    }
}
