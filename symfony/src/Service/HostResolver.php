<?php

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Cached IPv4 hostname resolution for the SSRF guard
 * ({@see HealthService::urlBlockedReason()}).
 *
 * gethostbynamel() has no timeout parameter, and on musl (Alpine) the
 * resolver timeout comes from /etc/resolv.conf only. The guard runs on nearly
 * every outbound call (Bazarr, Tautulli, health probes, ...), so an
 * unreachable resolver used to stall a PHP worker for the full resolver
 * timeout on EVERY call. This class bounds that cost:
 *
 *  - each host is resolved at most once per TTL window, positive AND negative
 *    results (a failed lookup is remembered like any other answer — the guard
 *    treats "no answer" as "not blocked", unchanged);
 *  - a lookup that was itself slow (the resolver is struggling) is remembered
 *    for longer, so a dead DNS server costs one stall per SLOW_TTL, not per call;
 *  - the answer lives in a per-process memo (worker mode: bounded by TTL expiry
 *    and MAX_ENTRIES, so a changed DNS answer is never pinned) and, when a pool
 *    is wired (Kernel::boot -> cache.app), in a shared cache so classic
 *    one-process-per-request FrankenPHP benefits as well.
 */
final class HostResolver
{
    /** Seconds a resolution result is trusted. */
    public const TTL = 60;
    /** A lookup slower than this many seconds is considered a struggling resolver... */
    public const SLOW_AFTER = 1.0;
    /** ...and its result (usually "no answer") is kept this long. */
    public const SLOW_TTL = 300;
    /** Hard cap of the per-process memo; it is simply emptied when exceeded. */
    public const MAX_ENTRIES = 256;

    /** @var array<string, array{0: float, 1: list<string>}> host => [expiresAt, ips] */
    private static array $memo = [];

    private static ?CacheItemPoolInterface $pool = null;

    /** @var (callable(string): (array<int, string>|false))|null */
    private static $resolver = null;

    /** @var (callable(): float)|null */
    private static $clock = null;

    private function __construct()
    {
    }

    /**
     * IPv4 addresses of $host, or [] when it cannot be resolved.
     *
     * @return list<string>
     */
    public static function resolve(string $host): array
    {
        $host = strtolower($host);
        $now = self::now();

        $hit = self::$memo[$host] ?? null;
        if ($hit !== null && $hit[0] > $now) {
            return $hit[1];
        }

        $shared = self::readShared($host, $now);
        if ($shared !== null) {
            self::remember($host, $shared[1], $shared[0]);
            return $shared[1];
        }

        $start = self::now();
        $raw = self::$resolver !== null ? (self::$resolver)($host) : @gethostbynamel($host);
        $elapsed = self::now() - $start;

        $ips = is_array($raw) ? array_values(array_map('strval', $raw)) : [];
        $ttl = $elapsed >= self::SLOW_AFTER ? self::SLOW_TTL : self::TTL;
        $expiresAt = self::now() + $ttl;

        self::remember($host, $ips, $expiresAt);
        self::writeShared($host, $ips, $expiresAt, $ttl);

        return $ips;
    }

    /** Wire (or unwire, with null) the shared cache. Called from Kernel::boot(). */
    public static function usePool(?CacheItemPoolInterface $pool): void
    {
        self::$pool = $pool;
    }

    /** Drop the per-process memo (the shared pool is untouched). */
    public static function reset(): void
    {
        self::$memo = [];
    }

    /**
     * Replace the OS resolver. Test seam only — never set in production.
     *
     * @param (callable(string): (array<int, string>|false))|null $resolver
     */
    public static function setResolver(?callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * Replace the clock (seconds, float). Test seam only.
     *
     * @param (callable(): float)|null $clock
     */
    public static function setClock(?callable $clock): void
    {
        self::$clock = $clock;
    }

    public static function memoSize(): int
    {
        return count(self::$memo);
    }

    private static function now(): float
    {
        return self::$clock !== null ? (float) (self::$clock)() : microtime(true);
    }

    /** @param list<string> $ips */
    private static function remember(string $host, array $ips, float $expiresAt): void
    {
        if (!isset(self::$memo[$host]) && count(self::$memo) >= self::MAX_ENTRIES) {
            self::$memo = [];
        }
        self::$memo[$host] = [$expiresAt, $ips];
    }

    /** @return array{0: float, 1: list<string>}|null [expiresAt, ips] */
    private static function readShared(string $host, float $now): ?array
    {
        if (self::$pool === null) {
            return null;
        }
        try {
            $item = self::$pool->getItem(self::key($host));
            if (!$item->isHit()) {
                return null;
            }
            $v = $item->get();
            if (!is_array($v) || !isset($v['exp'], $v['ips']) || !is_array($v['ips']) || (float) $v['exp'] <= $now) {
                return null;
            }
            return [(float) $v['exp'], array_values(array_map('strval', $v['ips']))];
        } catch (\Throwable) {
            return null; // a broken cache must never break the guard
        }
    }

    /** @param list<string> $ips */
    private static function writeShared(string $host, array $ips, float $expiresAt, int $ttl): void
    {
        if (self::$pool === null) {
            return;
        }
        try {
            $item = self::$pool->getItem(self::key($host));
            $item->set(['exp' => $expiresAt, 'ips' => $ips])->expiresAfter($ttl);
            self::$pool->save($item);
        } catch (\Throwable) {
            // best effort
        }
    }

    private static function key(string $host): string
    {
        return 'dns.v4.' . sha1($host);
    }
}
