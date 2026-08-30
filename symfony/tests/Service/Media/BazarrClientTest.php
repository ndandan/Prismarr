<?php

namespace App\Tests\Service\Media;

use App\Service\ConfigService;
use App\Service\Media\BazarrClient;
use App\Service\Media\ServiceHealthCache;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[AllowMockObjectsWithoutExpectations]
class BazarrClientTest extends TestCase
{
    private function config(array $values): ConfigService
    {
        $c = $this->createMock(ConfigService::class);
        $c->method('get')->willReturnCallback(fn(string $k) => $values[$k] ?? null);
        $c->method('has')->willReturnCallback(fn(string $k) => isset($values[$k]) && $values[$k] !== '');
        return $c;
    }

    private function client(array $values): BazarrClient
    {
        return new BazarrClient($this->config($values), new NullLogger(), new ServiceHealthCache(new ArrayAdapter()));
    }

    public function testPingFalseWhenUnconfigured(): void
    {
        $this->assertFalse($this->client([])->ping());
    }

    public function testPingFalseWhenDisabled(): void
    {
        $this->assertFalse($this->client([
            'bazarr_enabled' => '0', 'bazarr_url' => 'http://x:6767', 'bazarr_api_key' => 'k',
        ])->ping());
    }

    public function testGetMoviesEmptyWhenUnconfigured(): void
    {
        $this->assertSame([], $this->client([])->getMovies());
    }

    public function testGetWantedMoviesEmptyWhenUnconfigured(): void
    {
        $this->assertSame([], $this->client([])->getWantedMovies());
    }

    public function testGetWantedEpisodesEmptyWhenUnconfigured(): void
    {
        $this->assertSame([], $this->client([])->getWantedEpisodes());
    }

    public function testGetHistoryMoviesEmptyWhenUnconfigured(): void
    {
        $this->assertSame([], $this->client([])->getHistoryMovies());
    }

    public function testGetHistoryEpisodesEmptyWhenUnconfigured(): void
    {
        $this->assertSame([], $this->client([])->getHistoryEpisodes());
    }

    public function testGetEpisodesEmptyWhenUnconfigured(): void
    {
        $this->assertSame([], $this->client([])->getEpisodes(7));
    }

    public function testGetBadgeCountsZeroWhenUnconfigured(): void
    {
        $this->assertSame(['movies' => 0, 'episodes' => 0, 'providers' => 0], $this->client([])->getBadgeCounts());
    }

    public function testDownloadBodyNormalizesBooleansAndIdKey(): void
    {
        $client = $this->client([]);
        $m = (new \ReflectionClass($client))->getMethod('downloadBody');
        $m->setAccessible(true);
        $body = $m->invoke($client, [
            'radarrid' => 42, 'hi' => true, 'forced' => false,
            'original_format' => false, 'provider' => 'opensubtitles', 'subtitle' => 'abc',
        ], 'radarrid');
        $this->assertSame('42', $body['radarrid']);
        $this->assertSame('True', $body['hi']);
        $this->assertSame('False', $body['forced']);
        $this->assertSame('False', $body['original_format']);
        $this->assertSame('opensubtitles', $body['provider']);
        $this->assertSame('abc', $body['subtitle']);
    }

    public function testSearchMovieNullWhenUnconfigured(): void
    {
        $this->assertNull($this->client([])->searchMovie(42));
    }

    public function testDownloadMovieFalseWhenUnconfigured(): void
    {
        $this->assertFalse($this->client([])->downloadMovie(['radarrid' => 42]));
    }

    public function testSearchEpisodeNullWhenUnconfigured(): void
    {
        $this->assertNull($this->client([])->searchEpisode(7));
    }

    public function testDownloadEpisodeFalseWhenUnconfigured(): void
    {
        $this->assertFalse($this->client([])->downloadEpisode(['episodeid' => 7]));
    }

    public function testSearchMissingMovieFalseWhenUnconfigured(): void
    {
        $this->assertFalse($this->client([])->searchMissingMovie(42));
    }

    public function testSearchMissingSeriesFalseWhenUnconfigured(): void
    {
        $this->assertFalse($this->client([])->searchMissingSeries(7));
    }

    /** Config that passes ready() so request() actually runs. */
    private function configured(): array
    {
        return ['bazarr_url' => 'http://bazarr.invalid:6767', 'bazarr_api_key' => 'k'];
    }

    /**
     * BazarrClient with the cURL transfer stubbed out, so the response
     * classification branches (transport failure / non-2xx / invalid JSON /
     * 2xx) can be driven without a live Bazarr.
     */
    private function fakeClient(ServiceHealthCache $health, string|false $body, int $code, string $err = ''): BazarrClient
    {
        return new class ($this->config($this->configured()), new NullLogger(), $health, $body, $code, $err) extends BazarrClient {
            public function __construct(
                ConfigService $config,
                LoggerInterface $logger,
                ServiceHealthCache $health,
                private readonly string|false $fakeBody,
                private readonly int $fakeCode,
                private readonly string $fakeErr,
            ) {
                parent::__construct($config, $logger, $health);
            }

            protected function exec(\CurlHandle $ch): array
            {
                curl_close($ch);
                return [$this->fakeBody, $this->fakeCode, $this->fakeErr];
            }
        };
    }

    public function testNonTwoHundredResponseDoesNotTripTheBreaker(): void
    {
        // A reachable host that answers 404/500 is UP — only the call failed.
        // Marking it down would blind every other Bazarr call for the full
        // breaker TTL (10 s) because of one bad request.
        foreach ([404, 500] as $status) {
            $health = new ServiceHealthCache(new ArrayAdapter());
            $client = $this->fakeClient($health, '{"error":"nope"}', $status);

            $this->assertFalse($client->ping(), 'HTTP ' . $status . ' is still a failed call');
            $this->assertFalse(
                $health->isDown(BazarrClient::SERVICE),
                'HTTP ' . $status . ' must NOT mark Bazarr down'
            );
            $this->assertSame($status, $client->getLastError()['code']);
        }
    }

    public function testNonTwoHundredResponseCallsClearAndNeverMarkDown(): void
    {
        // Same rule stated as call expectations (the ArrayAdapter version
        // above can't distinguish "clear() called" from "nothing happened").
        $health = $this->createMock(ServiceHealthCache::class);
        $health->method('isDown')->willReturn(false);
        $health->expects($this->once())->method('clear')->with(BazarrClient::SERVICE);
        $health->expects($this->never())->method('markDown');

        $this->fakeClient($health, 'unauthorized', 401)->ping();
    }

    public function testTransportFailureCallsMarkDownAndNeverClear(): void
    {
        $health = $this->createMock(ServiceHealthCache::class);
        $health->method('isDown')->willReturn(false);
        $health->expects($this->once())->method('markDown')->with(BazarrClient::SERVICE);
        $health->expects($this->never())->method('clear');

        $this->fakeClient($health, false, 0, 'connect() timed out')->ping();
    }

    public function testTransportFailureMarksBazarrDown(): void
    {
        $health = new ServiceHealthCache(new ArrayAdapter());
        $client = $this->fakeClient($health, false, 0, 'Could not resolve host: bazarr.invalid');

        $this->assertFalse($client->ping());
        $this->assertTrue(
            $health->isDown(BazarrClient::SERVICE),
            'A transport failure is the one thing that MUST trip the breaker'
        );
        $this->assertSame('Could not resolve host: bazarr.invalid', $client->getLastError()['message']);
    }

    public function testInvalidJsonMarksBazarrDown(): void
    {
        $health = new ServiceHealthCache(new ArrayAdapter());
        $client = $this->fakeClient($health, '<html>reverse proxy</html>', 200);

        $this->assertFalse($client->ping());
        $this->assertTrue($health->isDown(BazarrClient::SERVICE));
        $this->assertSame('invalid JSON response', $client->getLastError()['message']);
    }

    public function testSuccessfulResponseClearsTheBreakerAndTheLastError(): void
    {
        $health = new ServiceHealthCache(new ArrayAdapter());
        $client = $this->fakeClient($health, '{"bazarr_version":"1.4.0"}', 200);

        $this->assertTrue($client->ping());
        $this->assertFalse($health->isDown(BazarrClient::SERVICE));
        $this->assertNull($client->getLastError());
    }

    public function testOpenBreakerSurfacesAStructuredError(): void
    {
        // F12: a breaker-open call used to return null with getLastError()
        // still null, so jsonClientError() answered "unknown error".
        $health = new ServiceHealthCache(new ArrayAdapter());
        $health->markDown(BazarrClient::SERVICE);
        $client = $this->fakeClient($health, '{"ok":true}', 200);

        $this->assertFalse($client->ping());

        $err = $client->getLastError();
        $this->assertNotNull($err);
        $this->assertSame(0, $err['code']);
        $this->assertSame('GET', $err['method']);
        $this->assertSame('/system/status', $err['path']);
        $this->assertStringContainsString('circuit open', $err['message']);
    }
}
