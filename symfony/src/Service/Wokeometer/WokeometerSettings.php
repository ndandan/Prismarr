<?php

namespace App\Service\Wokeometer;

use App\Service\ConfigService;

/**
 * Typed view over the Wokeometer settings rows (`setting` table via
 * ConfigService, which memoises per request and is ResetInterface itself —
 * this class holds no state of its own).
 *
 *  - `wokeometer_api_key`   — the `wok_…` bearer key; empty/missing = unconfigured.
 *  - `wokeometer_enabled`   — '0' = off; anything else or missing = on.
 *  - `wokeometer_auto_sync` — '0' = off; anything else or missing = on.
 *
 * Non-final: consumers' tests mock it.
 */
class WokeometerSettings
{
    public const KEY_API_KEY   = 'wokeometer_api_key';
    public const KEY_ENABLED   = 'wokeometer_enabled';
    public const KEY_AUTO_SYNC = 'wokeometer_auto_sync';

    /** Sync frequency is fixed ("Monthly"), not configurable. */
    public const SYNC_INTERVAL_DAYS = 30;

    public const PUBLIC_BASE_URL = 'https://wokeometer.app';

    /** Public page slugs we are willing to put in a link (`D` = no trailing-newline match). */
    private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]*$/iD';

    public function __construct(
        private readonly ConfigService $config,
    ) {}

    /** Key present AND not explicitly switched off. */
    public function isEnabled(): bool
    {
        return $this->apiKey() !== null && $this->config->get(self::KEY_ENABLED) !== '0';
    }

    public function apiKey(): ?string
    {
        $key = trim((string) $this->config->get(self::KEY_API_KEY));
        return $key !== '' ? $key : null;
    }

    /**
     * Drop ConfigService's per-request memo so the next read sees the
     * current `setting` rows. The sync calls it before every page: a
     * long-lived worker chunk must notice a key change or an "off" switch
     * saved while it runs.
     */
    public function refresh(): void
    {
        $this->config->invalidate();
    }

    public function isAutoSyncEnabled(): bool
    {
        return $this->config->get(self::KEY_AUTO_SYNC) !== '0';
    }

    /**
     * Public Wokeometer page for a title, or null when the slug is missing /
     * not a plain `[a-z0-9-]` slug or the media type is not movie|tv.
     */
    public function publicUrl(string $mediaType, ?string $slug): ?string
    {
        if (!in_array($mediaType, ['movie', 'tv'], true) || $slug === null || preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            return null;
        }

        return self::PUBLIC_BASE_URL . '/media/' . $mediaType . '/' . $slug;
    }
}
