<?php
namespace App\Tests\Service;

use App\Service\ConfigService;
use App\Service\DashboardLayoutService;
use PHPUnit\Framework\TestCase;

final class DashboardLayoutServiceTest extends TestCase
{
    /** @param array<string,?string> $stored */
    private function serviceFor(array $stored): DashboardLayoutService
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('get')->willReturnCallback(fn(string $k) => $stored[$k] ?? null);
        return new DashboardLayoutService($config);
    }

    public function testEmptyConfigGivesDefaultOrderAllVisible(): void
    {
        $r = $this->serviceFor([])->resolve();
        self::assertSame(
            ['upcoming', 'requests', 'health', 'plex', 'watchlist', 'trending', 'recent'],
            array_column($r, 'key'),
        );
        foreach ($r as $row) {
            self::assertTrue($row['visible']);
        }
    }

    public function testStoredOrderIsHonored(): void
    {
        $r = $this->serviceFor(['dashboard_section_order' => 'recent,plex,upcoming'])->resolve();
        // Stored keys first, in stored order; the rest appended in default order.
        self::assertSame(
            ['recent', 'plex', 'upcoming', 'requests', 'health', 'watchlist', 'trending'],
            array_column($r, 'key'),
        );
    }

    public function testUnknownKeysAreDroppedAndMissingAppended(): void
    {
        $r = $this->serviceFor(['dashboard_section_order' => 'trending,bogus,recent'])->resolve();
        $keys = array_column($r, 'key');
        self::assertNotContains('bogus', $keys);
        self::assertSame(['trending', 'recent', 'upcoming', 'requests', 'health', 'plex', 'watchlist'], $keys);
    }

    public function testDuplicateKeysAreCollapsed(): void
    {
        $r = $this->serviceFor(['dashboard_section_order' => 'plex,plex,recent'])->resolve();
        $keys = array_column($r, 'key');
        self::assertSame(1, count(array_keys($keys, 'plex', true)));
    }

    public function testHiddenFlagMarksSectionNotVisible(): void
    {
        $r = $this->serviceFor(['dashboard_hide_health' => '1'])->resolve();
        $byKey = array_column($r, 'visible', 'key');
        self::assertFalse($byKey['health']);
        self::assertTrue($byKey['plex']);
    }

    public function testResolutionIsCachedUntilReset(): void
    {
        $svc = $this->serviceFor(['dashboard_section_order' => 'recent']);
        $first = $svc->resolve();
        $svc->reset();
        $second = $svc->resolve();
        self::assertSame($first, $second);
    }
}
