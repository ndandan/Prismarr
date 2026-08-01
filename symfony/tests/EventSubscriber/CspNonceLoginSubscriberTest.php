<?php

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\CspNonceLoginSubscriber;
use App\Service\CspNonceGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

#[AllowMockObjectsWithoutExpectations]
class CspNonceLoginSubscriberTest extends TestCase
{
    private function loginSuccessEvent(Request $request): LoginSuccessEvent
    {
        return new LoginSuccessEvent(
            $this->createMock(AuthenticatorInterface::class),
            $this->createMock(Passport::class),
            $this->createMock(TokenInterface::class),
            $request,
            null,
            'main',
        );
    }

    public function testRemovesTheNonceFromASessionThatHasOne(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set(CspNonceGenerator::SESSION_KEY, 'a-pre-auth-nonce');

        $request = Request::create('/login', 'POST');
        $request->setSession($session);

        (new CspNonceLoginSubscriber())->onLoginSuccess($this->loginSuccessEvent($request));

        self::assertFalse($session->has(CspNonceGenerator::SESSION_KEY));
    }

    public function testDoesNothingWhenTheRequestHasNoSession(): void
    {
        // No setSession() call: hasSession() is false, same as a stateless
        // request would look like. Must not throw.
        $request = Request::create('/login', 'POST');

        (new CspNonceLoginSubscriber())->onLoginSuccess($this->loginSuccessEvent($request));

        self::assertFalse($request->hasSession());
    }
}
