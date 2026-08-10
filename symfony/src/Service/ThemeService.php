<?php
namespace App\Service;

use App\Theme\ColorMath;
use App\Theme\ThemePresets;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Resolves the admin-selected `display_theme` preset into a flat map of
 * concrete CSS variables, injected by base.html.twig before first paint.
 *
 * Resolution happens server-side (not via in-browser CSS calc) so there is
 * no flash, the math is unit-testable, and Tabler's `--tblr-primary-rgb`
 * tuple — which pure CSS cannot derive from hsl() — is precomputed here.
 *
 * Implements ResetInterface so an admin's theme change takes effect without
 * waiting for a FrankenPHP worker recycle (mirrors DisplayPreferencesService).
 */
final class ThemeService implements ResetInterface
{
    /** @var array<string,mixed>|null */
    private ?array $cache = null;

    public function __construct(private readonly ConfigService $config) {}

    public function reset(): void
    {
        $this->cache = null;
    }

    /**
     * Restricted to the 7 variables classic mode ever touched pre-themes —
     * unlike a preset's full css map, it must NOT set --tblr-body-color /
     * --tblr-secondary-color / --tblr-success / --tblr-danger, so Tabler's
     * own per-scheme defaults keep applying exactly as before this feature.
     */
    private const CLASSIC_CSS_KEYS = [
        '--tblr-body-bg',
        '--prismarr-surface',
        '--prismarr-surface-2',
        '--prismarr-sidebar',
        '--prismarr-topbar-bg',
        '--tblr-border-color',
        '--prismarr-border',
    ];

    /** Hand-authored: classic light mode was never routed through the HSL engine. */
    private const CLASSIC_LIGHT_CSS = [
        '--tblr-body-bg'       => '#f4f6fb',
        '--prismarr-surface'   => '#ffffff',
        '--prismarr-surface-2' => '#ffffff',
        '--prismarr-sidebar'   => '#ffffff',
        '--prismarr-topbar-bg' => 'rgba(250, 250, 252, 0.97)',
        '--tblr-border-color'  => 'rgba(0, 0, 0, 0.08)',
        '--prismarr-border'    => 'rgba(0, 0, 0, 0.08)',
    ];

    /**
     * @return array{key:string,active:bool,light:?bool,primary_hex:?string,primary_rgb:?string,css:array<string,string>,css_dark?:array<string,string>,css_light?:array<string,string>}
     */
    public function resolve(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $stored = $this->config->get('display_theme');

        // Un-set / explicit "classic" / a stale key from a removed preset all
        // fall back to classic — the manual light/dark toggle, not a preset.
        if (!is_string($stored) || $stored === ThemePresets::CLASSIC_KEY || !isset(ThemePresets::PRESETS[$stored])) {
            $dark = $this->resolvePreset(ThemePresets::PRESETS[ThemePresets::DEFAULT_KEY]);
            $this->cache = [
                'key'         => ThemePresets::CLASSIC_KEY,
                'active'      => false,
                'light'       => null,
                'primary_hex' => null,
                'primary_rgb' => null,
                'css'         => [],
                'css_dark'    => array_intersect_key($dark['css'], array_flip(self::CLASSIC_CSS_KEYS)),
                'css_light'   => self::CLASSIC_LIGHT_CSS,
            ];
            return $this->cache;
        }

        $this->cache = ['key' => $stored, 'active' => true] + $this->resolvePreset(ThemePresets::PRESETS[$stored]);

        return $this->cache;
    }

    /**
     * @param array{light:bool,bg:array{0:int,1:float,2:float},primary:array{0:int,1:float,2:float},positive:array{0:int,1:float,2:float},negative:array{0:int,1:float,2:float},contrast:float,textSaturation:float} $p
     * @return array{light:bool,primary_hex:string,primary_rgb:string,css:array<string,string>}
     */
    private function resolvePreset(array $p): array
    {
        $light = $p['light'];

        [$bh, $bs, $bl] = $p['bg'];
        // Surface tiers. Dark themes: cards/footer lift ABOVE the background
        // while the sidebar sits BELOW it — reproduces the hand-tuned
        // #1c1c1c / #161616 / #0d0d0d palette for `midnight`. Light themes:
        // everything trends toward white.
        if ($light) {
            $surface  = $this->hsl($bh, $bs, ColorMath::clamp($bl + 3));
            $surface2 = $this->hsl($bh, $bs, ColorMath::clamp($bl + 1.5));
            $sidebar  = $this->hsl($bh, $bs, ColorMath::clamp($bl + 3));
        } else {
            $surface  = $this->hsl($bh, $bs, ColorMath::clamp($bl + 4.5));
            $surface2 = $this->hsl($bh, $bs, ColorMath::clamp($bl + 2));
            $sidebar  = $this->hsl($bh, $bs, ColorMath::clamp($bl - 1.5));
        }

        // Border + text are alpha overlays whose strength scales with the
        // contrast multiplier (glance's `contrast-multiplier`).
        $contrast = (float) $p['contrast'];
        $borderAlpha = round(($light ? 0.10 : 0.08) * $contrast, 3);
        $border = $light
            ? "rgba(0, 0, 0, $borderAlpha)"
            : "rgba(255, 255, 255, $borderAlpha)";

        // Body text: high-contrast against bg, saturation scaled by
        // text-saturation-multiplier.
        $textSat = ColorMath::clamp($bs * (float) $p['textSaturation']);
        $bodyL = $light ? ColorMath::clamp(20 / $contrast) : ColorMath::clamp(100 - 12 / $contrast);
        $secondaryL = $light ? ColorMath::clamp(38 / $contrast) : ColorMath::clamp(68 / $contrast);
        $bodyColor = $this->hsl($bh, $textSat, $bodyL);
        $secondaryColor = $this->hsl($bh, $textSat, $secondaryL);

        [$ph, $ps, $pl] = $p['primary'];
        [$poh, $pos, $pol] = $p['positive'];
        [$neh, $nes, $nel] = $p['negative'];

        return [
            'light'       => $light,
            'primary_hex' => ColorMath::hslToHex($ph, $ps, $pl),
            'primary_rgb' => ColorMath::hslToRgbTuple($ph, $ps, $pl),
            'css'         => [
                '--tblr-body-bg'        => $this->hsl($bh, $bs, $bl),
                '--prismarr-surface'    => $surface,
                '--prismarr-surface-2'  => $surface2,
                '--prismarr-sidebar'    => $sidebar,
                // Sticky topbar: the background tint with slight translucency so
                // the blur(12px) backdrop still shows content scrolling beneath.
                // Tracks the theme bg, so it no longer stays stuck near-black on
                // non-midnight themes.
                '--prismarr-topbar-bg'  => sprintf('hsla(%d, %s%%, %s%%, 0.95)', $bh, $this->num($bs), $this->num($bl)),
                '--tblr-border-color'   => $border,
                '--prismarr-border'     => $border,
                '--tblr-body-color'     => $bodyColor,
                '--tblr-secondary-color'=> $secondaryColor,
                '--tblr-success'        => $this->hsl($poh, $pos, $pol),
                '--tblr-danger'         => $this->hsl($neh, $nes, $nel),
            ],
        ];
    }

    private function hsl(int $h, float $s, float $l): string
    {
        return sprintf('hsl(%d, %s%%, %s%%)', $h, $this->num($s), $this->num($l));
    }

    private function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
}
