<?php
namespace App\Tests\Controller;

use App\Controller\AdminSettingsController;
use App\Theme\ThemePresets;
use PHPUnit\Framework\TestCase;

final class AdminSettingsThemeTest extends TestCase
{
    public function testDisplayThemeOptionIsRegistered(): void
    {
        $opts = AdminSettingsController::DISPLAY_OPTIONS;
        self::assertArrayHasKey('display_theme', $opts);
        self::assertSame('select', $opts['display_theme']['type']);
        self::assertSame(ThemePresets::CLASSIC_KEY, $opts['display_theme']['default']);
    }

    public function testDisplayThemeOptionsMatchPresets(): void
    {
        $opts = AdminSettingsController::DISPLAY_OPTIONS['display_theme']['options'];
        self::assertSame(array_merge([ThemePresets::CLASSIC_KEY], ThemePresets::keys()), array_keys($opts));
        self::assertSame('admin.display.theme.preset.classic', $opts[ThemePresets::CLASSIC_KEY]);
        unset($opts[ThemePresets::CLASSIC_KEY]);
        self::assertSame(ThemePresets::optionLabels(), $opts);
    }

    public function testAccentPickerHasThemeDefaultOption(): void
    {
        $accent = AdminSettingsController::DISPLAY_OPTIONS['display_theme_color'];
        self::assertArrayHasKey('theme_default', $accent['options']);
        self::assertSame('theme_default', $accent['default']);
    }
}
