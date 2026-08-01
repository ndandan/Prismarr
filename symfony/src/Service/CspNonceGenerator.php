<?php

namespace App\Service;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Per-request nonce for the Content-Security-Policy script-src directive.
 *
 * Implements ResetInterface so that, in FrankenPHP worker mode, a fresh
 * nonce is minted per request (the container is otherwise kept alive for
 * minutes, and a reused nonce is a fixed public value that would let an
 * injected <script nonce="..."> execute — the CSP would look correct and
 * protect nothing).
 */
final class CspNonceGenerator implements ResetInterface
{
    private ?string $nonce = null;

    public function get(): string
    {
        return $this->nonce ??= base64_encode(random_bytes(16));
    }

    public function reset(): void
    {
        $this->nonce = null;
    }
}
