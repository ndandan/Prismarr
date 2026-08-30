<?php

namespace App\Tests\Controller;

use App\Tests\AbstractWebTestCase;

/**
 * The subtitle-language JSON endpoint the film-detail modal fetches on open.
 *
 * Like the other app_bazarr_api_* routes it is carved out of
 * ServiceRouteGuardSubscriber's `app_bazarr_` rule (exclude_prefix
 * `app_bazarr_api_`), so a background fetch gets JSON, never a 302 to HTML.
 * Unlike the mutation endpoints it is fail-closed to a 200 `tracked:false`
 * (matching the badge contract), not a 500 — gated / untracked / absent is
 * data, not an error.
 *
 * BazarrClient AND BazarrSubtitleIndex are both eagerly built at boot (via
 * HealthService), so neither can be swapped for a mock with
 * getContainer()->set(). Instead we drive the REAL index+controller+guard
 * chain and steer the result through the index's own documented 60 s cache
 * layer: seeding both pool keys makes the index serve a fixed "one tracked
 * movie" payload without a live Bazarr. The index's mapping logic itself is
 * unit-tested in BazarrSubtitleIndexLanguagesTest.
 */
class BazarrSubtitlesApiTest extends AbstractWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // cache.app is on the filesystem and survives kernel reboots between
        // test methods — start each test from an empty subtitle index so a
        // seeded movie from one test can't leak into another.
        static::getContainer()->get('cache.app')->clear();
    }

    /**
     * Seed the index's cross-request cache so the real index serves radarrId 5
     * as tracked (English present / French missing). loadMovies() only skips
     * its live fetch when BOTH pool keys are warm, so seed both.
     */
    private function seedMovie5(): void
    {
        $pool = static::getContainer()->get('cache.app');

        $status = $pool->getItem('bazarr_subtitle_index.movies');
        $status->set([5 => ['state' => 'missing', 'count' => 1]]);
        $pool->save($status);

        $langs = $pool->getItem('bazarr_subtitle_index.movie_langs');
        $langs->set([5 => [
            'present' => [['lang' => 'en', 'hi' => false, 'forced' => false]],
            'missing' => [['lang' => 'fr', 'hi' => false, 'forced' => false]],
            'tracked' => true,
        ]]);
        $pool->save($langs);
    }

    public function testMovieLanguagesReturnsPresentMissingTracked(): void
    {
        $this->seedMovie5();

        $this->client->request('GET', '/bazarr/api/subtitles/movie/5');

        self::assertFalse($this->client->getResponse()->isRedirect(), 'API route must never be 302d by the guard');
        self::assertResponseStatusCodeSame(200);
        self::assertJson((string) $this->client->getResponse()->getContent());

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($data['ok']);
        self::assertTrue($data['tracked']);
        self::assertSame([['lang' => 'en', 'hi' => false, 'forced' => false]], $data['present']);
        self::assertSame([['lang' => 'fr', 'hi' => false, 'forced' => false]], $data['missing']);
    }

    public function testAbsentMovieIsUntracked(): void
    {
        // Movie 5 is in the map, but 999 is not → fail-closed untracked shape.
        $this->seedMovie5();

        $this->client->request('GET', '/bazarr/api/subtitles/movie/999');

        self::assertFalse($this->client->getResponse()->isRedirect());
        self::assertResponseStatusCodeSame(200);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($data['ok']);
        self::assertFalse($data['tracked']);
        self::assertSame([], $data['present']);
        self::assertSame([], $data['missing']);
    }

    public function testAnonymousAccessIsDenied(): void
    {
        // AbstractWebTestCase::setUp() logs the client in as admin; drop the
        // session cookie to make the request anonymous (a second createClient()
        // would throw).
        $this->client->getCookieJar()->clear();

        $this->client->request('GET', '/bazarr/api/subtitles/movie/5');

        self::assertTrue(
            $this->client->getResponse()->isRedirect(),
            'ROLE_ADMIN endpoint must redirect anonymous users to login',
        );
    }

    /**
     * No seed: the real index + client with Bazarr unconfigured in the test
     * env. The endpoint must still answer a clean 200 `tracked:false`, never a
     * 302 (guard exemption) and never a 500 — the end-to-end proof of the
     * fail-closed contract the film modal depends on.
     */
    public function testUnconfiguredRealChainFailsClosed(): void
    {
        $this->client->request('GET', '/bazarr/api/subtitles/movie/5');

        self::assertFalse($this->client->getResponse()->isRedirect(), 'guard must leave app_bazarr_api_ routes alone');
        self::assertResponseStatusCodeSame(200);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($data['ok']);
        self::assertFalse($data['tracked']);
        self::assertSame([], $data['present']);
        self::assertSame([], $data['missing']);
    }
}
