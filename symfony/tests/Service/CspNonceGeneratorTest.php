<?php

namespace App\Tests\Service;

use App\Service\CspNonceGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * The nonce is stable *within a session* and rotates *between* sessions.
 *
 * Per-request rotation (the previous contract) killed Turbo Drive app-wide:
 * ux-turbo marks the importmap head scripts data-turbo-track="reload" and
 * the raw nonce is baked into the polyfill loader's script *body*, which
 * Turbo cannot blank before comparing head signatures — so every navigation
 * became a full reload. Session stability also keeps Turbo's re-stamping of
 * <meta name="csp-nonce"> onto body scripts consistent with the policy the
 * document was served with (mandatory once the header enforces).
 *
 * reset() therefore no longer mints a new value on its own: it only drops
 * the in-memory memo so a FrankenPHP worker cannot serve session A's nonce
 * into session B's response. Rotation now comes from the session, not the
 * request.
 */
class CspNonceGeneratorTest extends TestCase
{
    private function session(): Session
    {
        return new Session(new MockArraySessionStorage());
    }

    private function generatorFor(SessionInterface $session): CspNonceGenerator
    {
        $request = new Request();
        $request->setSession($session);

        $stack = new RequestStack();
        $stack->push($request);

        return new CspNonceGenerator($stack);
    }

    public function testNonceIsStableWithinOneRequest(): void
    {
        $gen = $this->generatorFor($this->session());

        self::assertSame($gen->get(), $gen->get());
    }

    public function testTheSameSessionKeepsItsNonceAcrossResetCycles(): void
    {
        $gen   = $this->generatorFor($this->session());
        $first = $gen->get();

        $gen->reset();
        self::assertSame($first, $gen->get(), 'the session attribute must win over the dropped memo');

        $gen->reset();
        self::assertSame($first, $gen->get(), 'still the same on a third request of the same session');
    }

    public function testTwoDifferentSessionsGetDifferentNonces(): void
    {
        $first  = $this->generatorFor($this->session())->get();
        $second = $this->generatorFor($this->session())->get();

        self::assertNotSame($first, $second, 'a nonce shared across sessions is a fixed public value');
    }

    public function testAnAlreadyStoredSessionNonceIsReused(): void
    {
        $session = $this->session();
        $session->set('_csp_nonce', 'cHJlLWV4aXN0aW5nLW5vbmNl');

        self::assertSame('cHJlLWV4aXN0aW5nLW5vbmNl', $this->generatorFor($session)->get());
    }

    public function testTheNonceIsStoredUnderTheSessionKeyAndIsNotTheSessionId(): void
    {
        $session = $this->session();
        $session->setId('4f1a2b3c4d5e6f70');

        $nonce = $this->generatorFor($session)->get();

        self::assertSame($nonce, $session->get('_csp_nonce'));
        // Deriving the nonce from the session id would publish a session
        // identifier (or a reversible slice of one) in every page's HTML.
        self::assertNotSame($session->getId(), $nonce);
        self::assertStringNotContainsString($session->getId(), $nonce);
    }

    public function testNoRequestFallsBackToAPerRequestNonce(): void
    {
        // CLI (console commands, cache:warmup) — nothing to attach to.
        $gen   = new CspNonceGenerator(new RequestStack());
        $first = $gen->get();

        self::assertSame($first, $gen->get(), 'still memoized within the request');

        $gen->reset();
        self::assertNotSame($first, $gen->get(), 'with no session, reset() must mint a fresh value');
    }

    public function testAnUnusableSessionFallsBackInsteadOfThrowing(): void
    {
        // Stateless shape: hasSession() is true (a factory is installed) but
        // resolving it blows up. The generator must never propagate that —
        // it runs on kernel.response for every single response.
        $request = new Request();
        $request->setSessionFactory(
            static fn(): SessionInterface => throw new SessionNotFoundException('stateless route'),
        );

        $stack = new RequestStack();
        $stack->push($request);

        self::assertTrue($request->hasSession());

        $gen   = new CspNonceGenerator($stack);
        $first = $gen->get();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9+\/]+={0,2}$/', $first);

        $gen->reset();
        self::assertNotSame($first, $gen->get());
    }

    public function testNonceIsBase64AndLongEnough(): void
    {
        foreach ([
            'session'  => $this->generatorFor($this->session())->get(),
            'fallback' => (new CspNonceGenerator(new RequestStack()))->get(),
        ] as $path => $nonce) {
            self::assertMatchesRegularExpression('/^[A-Za-z0-9+\/]+={0,2}$/', $nonce, $path);
            self::assertGreaterThanOrEqual(16, strlen(base64_decode($nonce, true) ?: ''), $path);
        }
    }
}
