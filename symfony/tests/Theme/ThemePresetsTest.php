<?php
namespace App\Tests\Theme;

use App\Theme\ColorMath;
use App\Theme\ThemePresets;
use PHPUnit\Framework\TestCase;

final class ThemePresetsTest extends TestCase
{
    public function testDefaultKeyExists(): void
    {
        self::assertArrayHasKey(ThemePresets::DEFAULT_KEY, ThemePresets::PRESETS);
        self::assertSame('midnight', ThemePresets::DEFAULT_KEY);
    }

    public function testMidnightReproducesCurrentDarkBackground(): void
    {
        $bg = ThemePresets::PRESETS['midnight']['bg'];
        // Current look is #111111 ≈ hsl(0,0%,7%).
        self::assertSame('#111111', ColorMath::hslToHex($bg[0], $bg[1], $bg[2]));
        self::assertFalse(ThemePresets::PRESETS['midnight']['light']);
    }

    public function testOledPresetsAreBlackAndOnlyBlackIsFlat(): void
    {
        foreach (['oled_black', 'oled_soft'] as $key) {
            self::assertArrayHasKey($key, ThemePresets::PRESETS);
            $bg = ThemePresets::PRESETS[$key]['bg'];
            self::assertSame('#000000', ColorMath::hslToHex($bg[0], $bg[1], $bg[2]), $key);
            self::assertFalse(ThemePresets::PRESETS[$key]['light'], $key);
        }
        self::assertTrue(ThemePresets::PRESETS['oled_black']['flat'] ?? false);
        self::assertFalse(ThemePresets::PRESETS['oled_soft']['flat'] ?? false);
        self::assertFalse(ThemePresets::PRESETS['midnight']['flat'] ?? false);
    }

    public function testEveryPresetHasCompleteShape(): void
    {
        foreach (ThemePresets::PRESETS as $key => $p) {
            foreach (['label_key', 'light', 'bg', 'primary', 'positive', 'negative', 'contrast', 'textSaturation'] as $field) {
                self::assertArrayHasKey($field, $p, "preset $key missing $field");
            }
            self::assertIsBool($p['light']);
            if (array_key_exists('flat', $p)) {
                self::assertIsBool($p['flat'], "preset $key.flat must be bool");
            }
            foreach (['bg', 'primary', 'positive', 'negative'] as $c) {
                self::assertCount(3, $p[$c], "preset $key.$c must be [H,S,L]");
            }
        }
    }

    public function testOptionLabelsMatchKeys(): void
    {
        self::assertSame(array_keys(ThemePresets::PRESETS), ThemePresets::keys());
        self::assertSame(
            array_keys(ThemePresets::PRESETS),
            array_keys(ThemePresets::optionLabels())
        );
    }
}
