<?php

namespace App\Tests\Service\Unifi;

use App\Service\Unifi\UnifiFetcher;

/**
 * Test double for the three Unifi readers. Payloads are keyed by a substring
 * of the path, mirroring UnifiClientTest's request() override, so a test names
 * endpoints the same way production code does.
 */
final class StubUnifiFetcher implements UnifiFetcher
{
    /** @var list<string> every path asked for, in order */
    public array $paths = [];
    /** @var list<?array> every body sent, index-aligned with $paths */
    public array $bodies = [];

    /** @param array<string, ?array> $responses keyed by path substring */
    public function __construct(
        private array $responses = [],
        private bool $failTransport = false,
    ) {}

    public function fetch(string $path, ?array $body = null): ?array
    {
        $this->paths[]  = $path;
        $this->bodies[] = $body;
        if ($this->failTransport) return null;
        foreach ($this->responses as $needle => $payload) {
            if (str_contains($path, $needle)) return $payload;
        }
        return null;
    }

    /** @var list<string> every v2 path asked for, in order (kept apart from $paths) */
    public array $v2Paths = [];

    /** Same needle map as fetch(): a v2 payload is keyed by e.g. 'aggregated-dashboard'. */
    public function fetchV2(string $path): ?array
    {
        $this->v2Paths[] = $path;
        if ($this->failTransport) return null;
        foreach ($this->responses as $needle => $payload) {
            if (str_contains($path, $needle)) return $payload;
        }
        return null;
    }

    public function transportFailed(): bool
    {
        return $this->failTransport;
    }

    /** Tests point two stubs at "different consoles" by giving them different ids. */
    public string $identity = 'stub-console';

    public function cacheIdentity(): string
    {
        return $this->identity;
    }
}
