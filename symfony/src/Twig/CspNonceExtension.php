<?php
namespace App\Twig;

use App\Service\CspNonceGenerator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the per-request CSP nonce to templates:
 *   <script nonce="{{ csp_nonce() }}">
 *
 * A function, not a global: Twig resolves extension globals once per
 * Environment, and the Environment is reused across requests in worker
 * mode, so a global would serve the first request's nonce forever.
 */
final class CspNonceExtension extends AbstractExtension
{
    public function __construct(private readonly CspNonceGenerator $nonce) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('csp_nonce', [$this->nonce, 'get']),
        ];
    }
}
