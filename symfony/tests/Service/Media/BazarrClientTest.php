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
}
