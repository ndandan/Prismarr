<?php

namespace App\Tests\EventSubscriber;

use App\Entity\ServiceInstance;
use App\EventSubscriber\CspHeaderSubscriber;
use App\Service\ConfigService;
use App\Service\CspNonceGenerator;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[AllowMockObjectsWithoutExpectations]
class CspHeaderSubscriberTest extends TestCase
{
    private function event(Response $response): ResponseEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        return new ResponseEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST, $response);
    }

    /**
     * Build a subscriber from a flat key=>url map. Keys "radarr_url" and
     * "sonarr_url" go through the instance provider (v1.1.0 source of truth);
     * other keys still use ConfigService::get().
     *
     * @param array<string, string> $urls
     */
    private function subscriberWithUrls(array $urls, string $frameAncestors = ''): CspHeaderSubscriber
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('get')->willReturnCallback(fn(string $key) => $urls[$key] ?? null);

        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->method('getEnabled')->willReturnCallback(function (string $type) use ($urls): array {
            $key = match ($type) {
                ServiceInstance::TYPE_RADARR => 'radarr_url',
                ServiceInstance::TYPE_SONARR => 'sonarr_url',
                default => null,
            };
            if ($key === null || !isset($urls[$key])) {
                return [];
            }
            $instance = new ServiceInstance($type, $type . '-1', ucfirst($type) . ' 1', $urls[$key]);
            return [$instance];
        });

        return new CspHeaderSubscriber($config, $instances, new CspNonceGenerator(new RequestStack()), $frameAncestors);
    }

    public function testSetsHeaderOnMainRequest(): void
    {
        $sub = $this->subscriberWithUrls([]);
        $response = new Response('<html></html>');
        $sub->onResponse($this->event($response));

        $this->assertTrue($response->headers->has('Content-Security-Policy'));
    }

    public function testStaticHostsAlwaysIncluded(): void
    {
        $sub = $this->subscriberWithUrls([]);
        $response = new Response();
        $sub->onResponse($this->event($response));

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('https://image.tmdb.org', $csp);
        $this->assertStringContainsString('https://ui-avatars.com', $csp);
        $this->assertStringContainsString('https://artworks.thetvdb.com', $csp);
    }

    public function testConfiguredRadarrUrlIsAddedToImgSrc(): void
    {
        $sub = $this->subscriberWithUrls([
            'radarr_url' => 'http://192.0.2.10:7878',
        ]);
        $response = new Response();
        $sub->onResponse($this->event($response));

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('http://192.0.2.10:7878', $csp);
    }

    public function testUrlWithPathIsReducedToOrigin(): void
    {
        // Input has a path (/api/v1), CSP must only contain scheme://host:port.
        $sub = $this->subscriberWithUrls([
            'sonarr_url' => 'http://localhost:8989/api/v3',
        ]);
        $response = new Response();
        $sub->onResponse($this->event($response));

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('http://localhost:8989', $csp);
        $this->assertStringNotContainsString('/api/v3', $csp);
    }

    public function testInvalidUrlIsIgnored(): void
    {
        $sub = $this->subscriberWithUrls([
            'radarr_url' => 'not a url',
        ]);
        $response = new Response();
        $sub->onResponse($this->event($response));

        $csp = $response->headers->get('Content-Security-Policy');
        // The static hosts are still present but the bad url is not propagated.
        $this->assertStringNotContainsString('not a url', $csp);
    }

    public function testExistingHeaderIsPreserved(): void
    {
        $sub = $this->subscriberWithUrls([]);
        $response = new Response();
        $response->headers->set('Content-Security-Policy', 'default-src none');
        $sub->onResponse($this->event($response));

        $this->assertSame('default-src none', $response->headers->get('Content-Security-Policy'));
    }

    public function testStrictDirectivesArePresent(): void
    {
        $sub = $this->subscriberWithUrls([]);
        $response = new Response();
        $sub->onResponse($this->event($response));

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
    }

    public function testEnforcingHeaderCarriesTheNonceAndNoInlineEscape(): void
    {
        $sub = $this->subscriberWithUrls([]);
        $response = new Response('<html></html>');
        $sub->onResponse($this->event($response));

        $csp = $response->headers->get('Content-Security-Policy');
        self::assertStringContainsString("script-src 'self' 'nonce-", $csp);

        // Scoped to the script-src directive itself, not the whole header:
        // style-src legitimately keeps 'unsafe-inline' (see
        // testStyleSrcKeepsUnsafeInline), so a whole-header substring check
        // for "'unsafe-inline' data:" would miss a regression where
        // script-src regains 'unsafe-inline' on its own, without the
        // trailing "data:" that happened to make the old two-token
        // substring unique in this header.
        $scriptSrc = self::directive($csp, 'script-src');
        self::assertStringNotContainsString("'unsafe-inline'", $scriptSrc);
        self::assertStringNotContainsString('data:', $scriptSrc);
    }

    /**
     * Extract a single directive's value out of a `;`-separated CSP header,
     * e.g. directive($csp, 'script-src') on
     * "default-src 'self'; script-src 'self' 'nonce-xyz'; ..." returns
     * "script-src 'self' 'nonce-xyz'".
     */
    private static function directive(string $csp, string $name): string
    {
        foreach (explode(';', $csp) as $part) {
            $part = trim($part);
            if (str_starts_with($part, $name . ' ')) {
                return $part;
            }
        }

        self::fail(sprintf('directive "%s" not found in CSP header: %s', $name, $csp));
    }

    public function testStyleSrcKeepsUnsafeInline(): void
    {
        $sub = $this->subscriberWithUrls([]);
        $response = new Response('<html></html>');
        $sub->onResponse($this->event($response));

        self::assertStringContainsString(
            "style-src 'self' 'unsafe-inline' https://rsms.me",
            $response->headers->get('Content-Security-Policy'),
        );
    }

    public function testReportOnlyHeaderIsGone(): void
    {
        $sub = $this->subscriberWithUrls([]);
        $response = new Response('<html></html>');
        $sub->onResponse($this->event($response));

        self::assertFalse($response->headers->has('Content-Security-Policy-Report-Only'));
    }

    public function testNonHtmlResponsesGetNoCspHeadersAtAll(): void
    {
        // CSP only governs document contexts, so a JSON API response has
        // nothing to gain from it — and computing the nonce for one is what
        // used to start a session (see the next test).
        $sub = $this->subscriberWithUrls([]);
        $response = new JsonResponse(['status' => 'ok']);
        $sub->onResponse($this->event($response));

        self::assertFalse($response->headers->has('Content-Security-Policy'));
        self::assertFalse($response->headers->has('Content-Security-Policy-Report-Only'));
        // X-Frame-Options is not a CSP header and costs no nonce, so it stays
        // exactly as it was before the gate — no security header is lost.
        self::assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
    }

    public function testAJsonResponseNeverTouchesTheSessionToMintANonce(): void
    {
        // The point of the gate. The nonce lives in a session attribute, and
        // reading it starts the session; the Docker healthcheck polls the
        // JSON /api/health every 30s without a cookie, so an ungated read
        // left one orphan session behind per poll.
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);

        $stack = new RequestStack();
        $stack->push($request);

        $sub = new CspHeaderSubscriber(
            $this->createMock(ConfigService::class),
            $this->createMock(ServiceInstanceProvider::class),
            new CspNonceGenerator($stack),
        );

        $sub->onResponse($this->event(new JsonResponse(['status' => 'ok'])));

        // Order matters: has() would start the session itself, so assert
        // isStarted() first.
        self::assertFalse($session->isStarted(), 'a JSON response must not start a session');
        self::assertFalse($session->has('_csp_nonce'));
    }

    public function testAResponseWithoutAContentTypeIsTreatedAsHtml(): void
    {
        // Symfony's Response defaults to text/html, and our own subscriber
        // runs after ResponseListener::prepare() — but a bare Response (what
        // most cases in this class build) must still get the full policy.
        $sub = $this->subscriberWithUrls([]);
        $response = new Response('<html></html>');
        self::assertNull($response->headers->get('Content-Type'));

        $sub->onResponse($this->event($response));

        self::assertTrue($response->headers->has('Content-Security-Policy'));
    }

    public function testAnHtmlContentTypeWithACharsetStillGetsThePolicy(): void
    {
        // What ResponseListener::prepare() actually leaves on a rendered page.
        $sub = $this->subscriberWithUrls([]);
        $response = new Response('<html></html>');
        $response->headers->set('Content-Type', 'text/html; charset=UTF-8');

        $sub->onResponse($this->event($response));

        self::assertStringContainsString(
            "script-src 'self' 'nonce-",
            (string) $response->headers->get('Content-Security-Policy'),
        );
    }

    public function testTurboStreamResponsesGetNoCspHeaders(): void
    {
        // text/vnd.turbo-stream.html documents governsADocument()'s boundary:
        // a stream response's <script> fragments are activated inside the
        // *existing* document and run under that document's own policy, not
        // whatever this response's headers would say. Deliberate, not an
        // oversight — see governsADocument()'s docblock.
        $sub = $this->subscriberWithUrls([]);
        $response = new Response('<turbo-stream></turbo-stream>');
        $response->headers->set('Content-Type', 'text/vnd.turbo-stream.html; charset=UTF-8');

        $sub->onResponse($this->event($response));

        self::assertFalse($response->headers->has('Content-Security-Policy'));
        self::assertFalse($response->headers->has('Content-Security-Policy-Report-Only'));
    }

    public function testXFrameOptionsSameOriginByDefault(): void
    {
        $sub = $this->subscriberWithUrls([]);
        $response = new Response();
        $sub->onResponse($this->event($response));

        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        $this->assertStringContainsString("frame-ancestors 'self';", $response->headers->get('Content-Security-Policy'));
    }

    public function testFrameAncestorsWidenedAndXFrameOptionsDroppedWhenEnvSet(): void
    {
        // Issue #25 — embed Prismarr in Organizr/Heimdall.
        $sub = $this->subscriberWithUrls([], 'https://organizr.example.com https://dash.example.org');
        $response = new Response();
        $sub->onResponse($this->event($response));

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'self' https://organizr.example.com https://dash.example.org;", $csp);
        $this->assertFalse($response->headers->has('X-Frame-Options'));
    }

    public function testFrameAncestorsStripsControlCharsToBlockHeaderInjection(): void
    {
        $sub = $this->subscriberWithUrls([], "https://evil.example\r\nSet-Cookie: x=1");
        $response = new Response();
        $sub->onResponse($this->event($response));

        $csp = $response->headers->get('Content-Security-Policy');
        // The CR/LF is gone — the leftover text is harmless inside the directive.
        $this->assertStringNotContainsString("\r", $csp);
        $this->assertStringNotContainsString("\n", $csp);
        $this->assertFalse($response->headers->has('Set-Cookie'));
    }

    public function testFrameAncestorsStripsCommasToAvoidMultiPolicyFootgun(): void
    {
        // A comma in a CSP header splits it into multiple policies which the
        // browser then enforces as their intersection. That can't weaken the
        // policy, but it can silently break the app for the admin who typo'd
        // it (e.g. `https://a.com, https://b.com` → second policy has only
        // frame-ancestors set, and default-src 'self' is *missing* there).
        // Stripping `,` removes the footgun.
        $sub = $this->subscriberWithUrls([], 'https://a.test, https://b.test');
        $response = new Response();
        $sub->onResponse($this->event($response));

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString(',', $csp);
        $this->assertStringContainsString('https://a.test', $csp);
        $this->assertStringContainsString('https://b.test', $csp);
    }

    public function testFrameAncestorsStripsSemicolonsToBlockDirectiveInjection(): void
    {
        // A `;` would close the frame-ancestors directive and let whatever
        // follows be parsed as a new CSP directive. The operator controls
        // the env so this isn't a web-attack surface, but stripping `;`
        // prevents a typo or a copy-paste accident from weakening the CSP.
        $sub = $this->subscriberWithUrls([], "https://a.test; default-src *");
        $response = new Response();
        $sub->onResponse($this->event($response));

        $csp = $response->headers->get('Content-Security-Policy');
        // The `;` after the origin would have closed frame-ancestors and let
        // "default-src *" be parsed as a fresh directive — with it stripped,
        // the leftover tokens stay inside frame-ancestors as harmless
        // unknown source expressions, and the original default-src 'self'
        // is the only one a browser sees.
        $this->assertStringNotContainsString('a.test;', $csp);
        $this->assertStringNotContainsString('; default-src *', $csp);
        $this->assertStringContainsString("default-src 'self';", $csp);
        $this->assertStringContainsString('https://a.test', $csp);
    }
}
