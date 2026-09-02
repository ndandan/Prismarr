<?php

namespace App\Service\Cache;

use App\Message\RefreshCacheKey;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The one stale-while-revalidate primitive (spec D1). Values live in
 * cache.app (filesystem, atomic tmp+rename writes) as {fetchedAt, value}
 * envelopes with expiresAfter(hardTtl), so a hard miss == a pool miss.
 *
 * Two read paths on purpose:
 *   read()          — never fetches. For datasets a page can render without
 *                     (Bazarr badges, the Bazarr tab).
 *   getOrCompute()  — fetches inline on a hard miss, THROUGH the contracts
 *                     cache so Symfony's LockRegistry flock still gives
 *                     cross-worker single-flight, and with $beta = 0 so
 *                     probabilistic early expiration cannot fire a surprise
 *                     inline refill before the hard TTL. For datasets a page
 *                     cannot render without (the library lists).
 *
 * Refreshes never run in the web process: requestRefresh() sets a short
 * coalescing marker and dispatches RefreshCacheKey to the `async` transport,
 * consumed by the already-running messenger-worker s6 service.
 */
final class StaleWhileRevalidateCache
{
    /** Seconds a coalescing marker suppresses further dispatches for one key. */
    public const MARKER_TTL = 30;

    /** Seconds an unanswered refresh request is remembered (dead-consumer probe). */
    public const REQUEST_TTL = 900;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly CacheItemPoolInterface $cacheApp,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {}

    /** @return array{value: mixed, state: 'fresh'|'stale'}|null null = hard miss */
    public function read(string $key, int $softTtl): ?array
    {
        $item = $this->cacheApp->getItem($key);
        if (!$item->isHit()) {
            return null;
        }

        $env = $item->get();
        if (!is_array($env) || !isset($env['fetchedAt']) || !array_key_exists('value', $env)) {
            // Shape from an older build (or a corrupt entry): treat as a miss
            // and drop it so getOrCompute() cannot hand it back.
            $this->cacheApp->deleteItem($key);

            return null;
        }

        return [
            'value' => $env['value'],
            'state' => (time() - (int) $env['fetchedAt']) < $softTtl ? 'fresh' : 'stale',
        ];
    }

    /**
     * @param callable():mixed $fetch
     * @return array{value: mixed, state: 'fresh'|'stale'}
     */
    public function getOrCompute(string $key, int $softTtl, int $hardTtl, callable $fetch): array
    {
        $hit = $this->read($key, $softTtl);
        if ($hit !== null) {
            return $hit;
        }

        /** @var array{fetchedAt: int, value: mixed} $env */
        $env = $this->cache->get(
            $key,
            static function (ItemInterface $item) use ($fetch, $hardTtl): array {
                $value = $fetch();
                // An empty list is a failed/empty fetch: return it to this
                // caller but never pin it for the hard window.
                $item->expiresAfter($value === [] ? 0 : $hardTtl);

                return ['fetchedAt' => time(), 'value' => $value];
            },
            0.0, // disable probabilistic early expiration
        );

        return ['value' => $env['value'], 'state' => 'fresh'];
    }

    public function write(string $key, mixed $value, int $hardTtl, ?int $fetchedAt = null): void
    {
        $item = $this->cacheApp->getItem($key);
        $item->set(['fetchedAt' => $fetchedAt ?? time(), 'value' => $value]);
        $item->expiresAfter($hardTtl);
        $this->cacheApp->save($item);

        // The request was answered; stop counting it as overdue.
        $this->cacheApp->deleteItem($key . '.requested_at');
    }

    public function delete(string $key): void
    {
        $this->cacheApp->deleteItems([$key, $key . '.refreshing', $key . '.requested_at']);
    }

    /**
     * Best-effort single flight. Never throws: a dispatch failure deletes the
     * marker it just set (otherwise the failure would blackhole every refresh
     * for the marker's full window) and is logged loudly.
     */
    public function requestRefresh(string $key): void
    {
        $marker = $this->cacheApp->getItem($key . '.refreshing');
        if ($marker->isHit()) {
            return;
        }

        $marker->set(true);
        $marker->expiresAfter(self::MARKER_TTL);
        $this->cacheApp->save($marker);

        $stamp = $this->cacheApp->getItem($key . '.requested_at');
        if (!$stamp->isHit()) {
            $stamp->set(time());
            $stamp->expiresAfter(self::REQUEST_TTL);
            $this->cacheApp->save($stamp);
        }

        try {
            $this->bus->dispatch(new RefreshCacheKey($key));
        } catch (\Throwable $e) {
            $this->cacheApp->deleteItem($key . '.refreshing');
            $this->logger->error('SWR refresh dispatch failed', [
                'key'       => $key,
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);
        }
    }

    /** True when a refresh was asked for at least $seconds ago and never answered. */
    public function refreshIsOverdue(string $key, int $seconds): bool
    {
        $stamp = $this->cacheApp->getItem($key . '.requested_at');
        if (!$stamp->isHit()) {
            return false;
        }

        return (time() - (int) $stamp->get()) >= $seconds;
    }
}
