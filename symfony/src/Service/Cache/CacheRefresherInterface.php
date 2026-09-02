<?php

namespace App\Service\Cache;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Owns the "how do I rebuild this cache entry" half of the SWR contract.
 * Implementations are collected by RefreshCacheKeyHandler through the
 * app.cache_refresher tag; the FIRST one whose supports() answers true wins.
 */
#[AutoconfigureTag('app.cache_refresher')]
interface CacheRefresherInterface
{
    public function supports(string $key): bool;

    /** Must be idempotent: a duplicate message may arrive. */
    public function refresh(string $key): void;
}
