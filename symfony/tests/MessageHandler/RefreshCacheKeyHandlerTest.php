<?php

namespace App\Tests\MessageHandler;

use App\Message\RefreshCacheKey;
use App\MessageHandler\RefreshCacheKeyHandler;
use App\Service\Cache\CacheRefresherInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class RefreshCacheKeyHandlerTest extends TestCase
{
    private function refresher(string $prefix, ?string &$seen): CacheRefresherInterface
    {
        return new class($prefix, $seen) implements CacheRefresherInterface {
            public function __construct(private string $prefix, private ?string &$seen) {}
            public function supports(string $key): bool { return str_starts_with($key, $this->prefix); }
            public function refresh(string $key): void { $this->seen = $key; }
        };
    }

    public function testRoutesTheKeyToTheOwningRefresher(): void
    {
        $a = null; $b = null;
        $handler = new RefreshCacheKeyHandler([$this->refresher('media.', $a), $this->refresher('bazarr_', $b)], new NullLogger());

        $handler(new RefreshCacheKey('bazarr_subtitle_index.movies'));

        $this->assertNull($a);
        $this->assertSame('bazarr_subtitle_index.movies', $b);
    }

    public function testFirstMatchingRefresherWinsAndOthersAreNotCalled(): void
    {
        $a = null; $b = null;
        $handler = new RefreshCacheKeyHandler([$this->refresher('media.', $a), $this->refresher('media.movies.', $b)], new NullLogger());

        $handler(new RefreshCacheKey('media.movies.radarr-1'));

        $this->assertSame('media.movies.radarr-1', $a);
        $this->assertNull($b);
    }

    public function testUnknownKeyIsAckedNotThrown(): void
    {
        $a = null;
        $handler = new RefreshCacheKeyHandler([$this->refresher('media.', $a)], new NullLogger());

        $handler(new RefreshCacheKey('nope.whatever')); // must not throw

        $this->assertNull($a);
    }
}
