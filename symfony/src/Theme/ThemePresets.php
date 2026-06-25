<?php
namespace App\Theme;

/**
 * Curated, glance-style theme presets. Authored in HSL + multipliers
 * (background / primary / positive / negative + contrast & text-saturation
 * multipliers + light flag), exactly like glance's theme model. ThemeService
 * resolves these into concrete CSS variables.
 *
 * `midnight` is the default and reproduces Prismarr's pre-themes dark look
 * (#111 background, indigo primary) so upgrades are a visual no-op.
 */
final class ThemePresets
{
    public const DEFAULT_KEY = 'midnight';

    public const PRESETS = [
        'midnight' => [
            'label_key'      => 'admin.display.theme.preset.midnight',
            'light'          => false,
            'bg'             => [0, 0, 6.5],       // #111111
            'primary'        => [239, 84, 66.8],   // #6366f1 indigo
            'positive'       => [142, 71, 45],     // green
            'negative'       => [0, 84, 60],       // red
            'contrast'       => 1.0,
            'textSaturation' => 1.0,
        ],
        'nord' => [
            'label_key'      => 'admin.display.theme.preset.nord',
            'light'          => false,
            'bg'             => [220, 16, 22],
            'primary'        => [213, 32, 70],
            'positive'       => [92, 28, 65],
            'negative'       => [354, 42, 56],
            'contrast'       => 1.0,
            'textSaturation' => 1.0,
        ],
        'catppuccin_mocha' => [
            'label_key'      => 'admin.display.theme.preset.catppuccin_mocha',
            'light'          => false,
            'bg'             => [240, 21, 12],
            'primary'        => [267, 84, 81],
            'positive'       => [115, 54, 76],
            'negative'       => [343, 81, 75],
            'contrast'       => 1.0,
            'textSaturation' => 1.0,
        ],
        'gruvbox' => [
            'label_key'      => 'admin.display.theme.preset.gruvbox',
            'light'          => false,
            'bg'             => [0, 0, 16],
            'primary'        => [42, 84, 56],
            'positive'       => [61, 66, 44],
            'negative'       => [6, 96, 59],
            'contrast'       => 1.05,
            'textSaturation' => 1.0,
        ],
        'solarized_light' => [
            'label_key'      => 'admin.display.theme.preset.solarized_light',
            'light'          => true,
            'bg'             => [44, 87, 94],
            'primary'        => [205, 69, 49],
            'positive'       => [68, 100, 30],
            'negative'       => [1, 71, 52],
            'contrast'       => 1.0,
            'textSaturation' => 1.0,
        ],
        'clean_light' => [
            'label_key'      => 'admin.display.theme.preset.clean_light',
            'light'          => true,
            'bg'             => [220, 23, 97],
            'primary'        => [239, 84, 60],
            'positive'       => [142, 71, 40],
            'negative'       => [0, 74, 50],
            'contrast'       => 1.0,
            'textSaturation' => 1.0,
        ],
    ];

    /** @return array<int,string> */
    public static function keys(): array
    {
        return array_keys(self::PRESETS);
    }

    /** @return array<string,string> key => label translation key */
    public static function optionLabels(): array
    {
        return array_map(static fn (array $p): string => $p['label_key'], self::PRESETS);
    }
}
