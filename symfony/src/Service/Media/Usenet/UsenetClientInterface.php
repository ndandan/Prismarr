<?php

namespace App\Service\Media\Usenet;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Common contract for the Usenet download clients (SABnzbd, NZBGet) so the
 * controller, health checks and sidebar badge stay downloader-agnostic. Mirrors
 * the shape of QBittorrentClient but normalized to Usenet semantics (no
 * seeding / ratio; jobs have an nzo_id / NZBID instead of a hash).
 *
 * Implementations own their own circuit breaker + SSRF-guarded HTTP, exactly
 * like QBittorrentClient, and record the last upstream error for the admin
 * "Test connection" hint via getLastError().
 */
interface UsenetClientInterface extends ResetInterface
{
    /** 'sabnzbd' | 'nzbget' — the service key used for config + health. */
    public function getKind(): string;

    /** True if the downloader answers and accepts the credentials. */
    public function ping(): bool;

    public function getVersion(): ?string;

    public function getQueue(): UsenetQueueSnapshot;

    /** @return UsenetDownload[] Most recent first. */
    public function getHistory(int $limit = 50): array;

    public function pauseAll(): bool;

    public function resumeAll(): bool;

    public function pauseItem(string $id): bool;

    public function resumeItem(string $id): bool;

    public function deleteItem(string $id, bool $deleteFiles = false): bool;

    /** @param int $bytesPerSec 0 = unlimited. */
    public function setSpeedLimitBytes(int $bytesPerSec): bool;

    public function addNzbFromUrl(string $url, ?string $category = null): bool;

    /**
     * @param array<array{content: string, name: string}> $files
     */
    public function addNzbFromFiles(array $files, ?string $category = null): bool;

    /**
     * Last upstream error captured by an HTTP method, or null if the most
     * recent call succeeded. Reset between worker requests.
     *
     * @return array{code:int, method:string, path:string, message:string}|null
     */
    public function getLastError(): ?array;
}
