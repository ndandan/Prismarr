<?php

namespace App\Service\Unifi;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The /unifi readers' TTL memo, held in the shared app cache.
 *
 * Review 2026-11-06 FF-1: the readers kept their memo on the instance and
 * implement ResetInterface, so the services_resetter wiped it after every
 * request — under worker mode, and with it off each request builds fresh
 * services anyway. Every 10 s poll therefore refetched the 300 s config
 * endpoints and the 300 s history reports. UnifiClient::overview() already
 * moved to cache.app for the same reason.
 *
 * Freshness is decided by the caller's clock ($now), not the pool's expiry, so
 * the readers' `nowOffset` test seam keeps working; the pool expiry is only an
 * upper bound that lets stale entries drop out. Keys carry the fetcher's
 * console identity (URL + site + key), so a settings change never serves the
 * previous console's data — and a settings save clears cache.app anyway.
 */
final class UnifiReadCache
{
    private readonly CacheItemPoolInterface $pool;

    public function __construct(
        #[Autowire(service: 'cache.app')]
        ?CacheItemPoolInterface $pool = null,
    ) {
        $this->pool = $pool ?? new ArrayAdapter();
    }

    /** The value stored for $group, or null when absent or older than $ttl at $now. */
    public function get(UnifiFetcher $unifi, string $group, float $ttl, float $now): mixed
    {
        $key = $this->key($unifi, $group);
        if ($key === null) {
            return null;
        }
        $item = $this->pool->getItem($key);
        if (!$item->isHit()) {
            return null;
        }
        $entry = $item->get();
        if (!is_array($entry) || !isset($entry['at']) || !array_key_exists('data', $entry)) {
            return null;
        }
        $age = $now - (float) $entry['at'];

        return ($age >= 0 && $age < $ttl) ? $entry['data'] : null;
    }

    public function put(UnifiFetcher $unifi, string $group, float $ttl, float $now, mixed $data): void
    {
        $key = $this->key($unifi, $group);
        if ($key === null) {
            return;
        }
        $item = $this->pool->getItem($key);
        $item->set(['at' => $now, 'data' => $data]);
        $item->expiresAfter((int) ceil($ttl) + 1);
        $this->pool->save($item);
    }

    private function key(UnifiFetcher $unifi, string $group): ?string
    {
        $identity = $unifi->cacheIdentity();
        if ($identity === '') {
            return null; // unconfigured: nothing worth sharing
        }

        // PSR-6 reserves {}()/\@: — the group may carry a node id (a MAC).
        return 'unifi.read.' . hash('xxh128', $group) . '.' . $identity;
    }
}
