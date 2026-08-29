<?php

namespace App\Tests\Service\Media;

use App\Service\ConfigService;
use App\Service\Media\BazarrClient;
use App\Service\Media\ServiceHealthCache;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
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
}
