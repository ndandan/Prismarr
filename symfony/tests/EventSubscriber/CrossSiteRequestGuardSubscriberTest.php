<?php

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\CrossSiteRequestGuardSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Most JSON POST endpoints authenticate the session but carry no CSRF
 * token, and the session cookie is only SameSite=Lax — which still rides
 * along on a POST from another port of the same host or a sibling
 * subdomain (both "same-site"). The guard rejects unsafe methods that the
 * browser itself labels as not same-origin.
 */
class CrossSiteRequestGuardSubscriberTest extends TestCase
{
    /** @param array<string, string> $headers */
    private static function request(string $method, array $headers = [], string $host = 'dash.dan-flix.us', string $scheme = 'http'): Request
    {
        $server = ['HTTP_HOST' => $host, 'HTTPS' => $scheme === 'https' ? 'on' : 'off'];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return Request::create($scheme . '://' . $host . '/bazarr/api/refresh', $method, [], [], [], $server);
    }

    /** @return iterable<string, array{Request, bool}> */
    public static function cases(): iterable
    {
        // [request, blocked?]
        yield 'GET is never blocked, whatever its origin' => [self::request('GET', ['Sec-Fetch-Site' => 'cross-site']), false];
        yield 'HEAD is never blocked' => [self::request('HEAD', ['Sec-Fetch-Site' => 'cross-site']), false];
        yield 'POST from the same origin' => [self::request('POST', ['Sec-Fetch-Site' => 'same-origin']), false];
        yield 'POST the user initiated directly (bookmark, address bar)' => [self::request('POST', ['Sec-Fetch-Site' => 'none']), false];
        yield 'POST from a sibling subdomain / another port (same-site)' => [self::request('POST', ['Sec-Fetch-Site' => 'same-site']), true];
        yield 'POST from another site' => [self::request('POST', ['Sec-Fetch-Site' => 'cross-site']), true];
        yield 'DELETE from another site' => [self::request('DELETE', ['Sec-Fetch-Site' => 'cross-site']), true];
        yield 'Sec-Fetch-Site wins over a matching Origin' => [self::request('POST', ['Sec-Fetch-Site' => 'same-site', 'Origin' => 'http://dash.dan-flix.us']), true];

        // No Sec-Fetch-Site (older browser): fall back to Origin, by host only —
        // a TLS-terminating tunnel/proxy can make the app see http while the
        // browser's Origin says https.
        yield 'Origin on the same host, scheme differs behind a TLS proxy' => [self::request('POST', ['Origin' => 'https://dash.dan-flix.us']), false];
        yield 'Origin on a sibling subdomain' => [self::request('POST', ['Origin' => 'https://sonarr.dan-flix.us']), true];
        yield 'Origin on another port of the same IP' => [self::request('POST', ['Origin' => 'http://192.168.68.83:8989'], '192.168.68.83:7070'), true];
        yield 'Origin on the same IP and port' => [self::request('POST', ['Origin' => 'http://192.168.68.83:7070'], '192.168.68.83:7070'), false];
        yield 'opaque Origin (sandboxed iframe, data: URL)' => [self::request('POST', ['Origin' => 'null']), true];

        // Neither header: not a browser cross-site request (curl, scripts,
        // server-to-server) — CSRF needs a victim browser, and every browser
        // that would attach the cookie sends at least Origin on a POST.
        yield 'no Sec-Fetch-Site and no Origin' => [self::request('POST'), false];
    }

    #[DataProvider('cases')]
    public function testBlockDecision(Request $request, bool $blocked): void
    {
        $this->assertSame($blocked, CrossSiteRequestGuardSubscriber::blockReason($request) !== null);
    }

    public function testABlockedRequestGetsA403BeforeAnyControllerRuns(): void
    {
        $request = self::request('POST', ['Sec-Fetch-Site' => 'same-site', 'Accept' => 'application/json']);
        $event   = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);

        (new CrossSiteRequestGuardSubscriber(new NullLogger()))->onKernelRequest($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertJson((string) $response->getContent(), 'JSON callers get JSON they can parse');
    }

    public function testAnAllowedRequestIsLeftAlone(): void
    {
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), self::request('POST', ['Sec-Fetch-Site' => 'same-origin']), HttpKernelInterface::MAIN_REQUEST);

        (new CrossSiteRequestGuardSubscriber(new NullLogger()))->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }

    public function testExtraTrustedOriginsCoverAProxyThatRewritesTheHost(): void
    {
        // A plain-HTTP reverse proxy that forwards Host=127.0.0.1:7070 while
        // the browser is on http://prismarr.lan: no Sec-Fetch-Site over plain
        // http, so only the Origin fallback decides — and every POST,
        // login included, would be refused without an escape hatch.
        $request = self::request('POST', ['Origin' => 'http://prismarr.lan'], '127.0.0.1:7070');

        $this->assertNotNull(CrossSiteRequestGuardSubscriber::blockReason($request));
        $this->assertNull(CrossSiteRequestGuardSubscriber::blockReason($request, ['prismarr.lan']));
        $this->assertNotNull(
            CrossSiteRequestGuardSubscriber::blockReason(self::request('POST', ['Sec-Fetch-Site' => 'same-site', 'Origin' => 'http://prismarr.lan']), ['prismarr.lan']),
            'the list never overrides what the browser itself reports'
        );
    }

    public function testTheTrustedOriginsEnvAcceptsHostsOrFullOrigins(): void
    {
        $guard   = new CrossSiteRequestGuardSubscriber(new NullLogger(), ' prismarr.lan , https://dash.example.com:8443 ,');
        $request = self::request('POST', ['Origin' => 'https://dash.example.com:8443'], '127.0.0.1:7070');
        $event   = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);

        $guard->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }
}
