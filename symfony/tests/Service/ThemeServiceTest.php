<?php
namespace App\Tests\Service;

use App\Service\ConfigService;
use App\Service\ThemeService;
use App\Theme\ThemePresets;
use PHPUnit\Framework\TestCase;

final class ThemeServiceTest extends TestCase
{
    private function serviceFor(?string $stored): ThemeService
    {
        $config = $this->createMock(ConfigService::class);
        $config->expects(self::atLeastOnce())->method('get')->with('display_theme')->willReturn($stored);
        return new ThemeService($config);
    }

    public function testUnknownKeyFallsBackToClassic(): void
    {
        $r = $this->serviceFor('does-not-exist')->resolve();
        self::assertSame(ThemePresets::CLASSIC_KEY, $r['key']);
        self::assertFalse($r['active']);
    }

    public function testNullStoredFallsBackToClassic(): void
    {
        $r = $this->serviceFor(null)->resolve();
        self::assertSame(ThemePresets::CLASSIC_KEY, $r['key']);
        self::assertFalse($r['active']);
        self::assertNull($r['light']);
        self::assertSame([], $r['css']);
    }

    public function testExplicitClassicKeyIsInactive(): void
    {
        $r = $this->serviceFor(ThemePresets::CLASSIC_KEY)->resolve();
        self::assertFalse($r['active']);
    }

    public function testClassicExposesDarkAndLightVariantsMatchingMidnightForDark(): void
    {
        $r = $this->serviceFor(null)->resolve();
        self::assertSame('hsl(0, 0%, 6.5%)', $r['css_dark']['--tblr-body-bg']);
        self::assertArrayNotHasKey('--tblr-success', $r['css_dark']);
        self::assertArrayNotHasKey('--tblr-body-color', $r['css_dark']);
        self::assertSame('#f4f6fb', $r['css_light']['--tblr-body-bg']);
    }

    public function testMidnightResolvesExpectedPrimaryAndBackground(): void
    {
        $r = $this->serviceFor('midnight')->resolve();
        self::assertTrue($r['active']);
        self::assertFalse($r['light']);
        self::assertSame('#6366f1', $r['primary_hex']);
        self::assertSame('99, 102, 241', $r['primary_rgb']);
        self::assertSame('hsl(0, 0%, 6.5%)', $r['css']['--tblr-body-bg']);
        self::assertSame('hsl(0, 0%, 11%)',  $r['css']['--prismarr-surface']);
        self::assertSame('hsl(0, 0%, 8.5%)', $r['css']['--prismarr-surface-2']);
        self::assertSame('hsl(0, 0%, 5%)',   $r['css']['--prismarr-sidebar']);
        self::assertArrayHasKey('--prismarr-surface', $r['css']);
        self::assertArrayHasKey('--tblr-border-color', $r['css']);
        self::assertSame('hsla(0, 0%, 6.5%, 0.95)', $r['css']['--prismarr-topbar-bg']);
    }

    public function testLightPresetSetsLightFlag(): void
    {
        $r = $this->serviceFor('catppuccin_latte')->resolve();
        self::assertTrue($r['light']);
    }

    public function testResolutionIsCachedUntilReset(): void
    {
        $svc = $this->serviceFor('midnight');
        $first = $svc->resolve();
        $svc->reset();
        $second = $svc->resolve();
        self::assertSame($first, $second);
    }
}
