<?php

namespace App\Tests\Service;

use App\Service\CspNonceGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The nonce must be stable within one request (every script tag on the
 * page needs the same value) and different across requests. The second
 * property is the one that matters in FrankenPHP worker mode: the
 * container is kept alive for minutes, so a nonce that survives reset()
 * becomes a fixed, publicly visible constant and the CSP stops
 * protecting anything while still looking correct.
 */
class CspNonceGeneratorTest extends TestCase
{
    public function testNonceIsStableWithinOneRequest(): void
    {
        $gen = new CspNonceGenerator();

        self::assertSame($gen->get(), $gen->get());
    }

    public function testResetYieldsANewNonce(): void
    {
        $gen = new CspNonceGenerator();
        $first = $gen->get();

        $gen->reset();

        self::assertNotSame($first, $gen->get(), 'a reused nonce defeats the CSP in worker mode');
    }

    public function testNonceIsBase64AndLongEnough(): void
    {
        $nonce = (new CspNonceGenerator())->get();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9+\/]+={0,2}$/', $nonce);
        self::assertGreaterThanOrEqual(16, strlen(base64_decode($nonce, true) ?: ''));
    }
}
