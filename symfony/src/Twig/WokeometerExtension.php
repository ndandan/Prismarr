<?php

namespace App\Twig;

use App\Service\Wokeometer\WokeometerSettings;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `wokeometer_enabled()` — Wokeometer switched on AND a usable key stored
 * (WokeometerSettings::isEnabled(); a settings read, never an API call).
 * The Films / Series modals use it to skip their local lookup fetch
 * entirely when the integration is off.
 */
final class WokeometerExtension extends AbstractExtension
{
    public function __construct(
        private readonly WokeometerSettings $settings,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('wokeometer_enabled', [$this, 'isEnabled']),
        ];
    }

    public function isEnabled(): bool
    {
        try {
            return $this->settings->isEnabled();
        } catch (\Throwable) {
            return false; // fail closed: an unreadable settings table hides the feature
        }
    }
}
