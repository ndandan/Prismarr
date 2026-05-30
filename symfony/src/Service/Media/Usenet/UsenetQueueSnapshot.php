<?php

namespace App\Service\Media\Usenet;

/**
 * Normalized snapshot of a Usenet downloader's queue — the global counters the
 * page header and the sidebar badge need, plus the per-item list. Returned by
 * {@see UsenetClientInterface::getQueue()} for both SABnzbd and NZBGet.
 */
final readonly class UsenetQueueSnapshot
{
    /**
     * @param UsenetDownload[] $items
     */
    public function __construct(
        public bool $paused,
        /** Global download rate in bytes/s. */
        public int $speedBytes,
        /** Configured speed cap in bytes/s, 0 = unlimited. */
        public int $speedLimitBytes,
        public int $remainingBytes,
        /** Items currently downloading — drives the sidebar badge. */
        public int $activeCount,
        public int $queuedCount,
        /** Whole-queue ETA in seconds, or null when unknown. */
        public ?int $etaSeconds,
        /** Free space on the download disk in bytes, 0 when unknown. */
        public int $freeSpaceBytes,
        public array $items,
    ) {}

    public static function empty(): self
    {
        return new self(false, 0, 0, 0, 0, 0, null, 0, []);
    }
}
