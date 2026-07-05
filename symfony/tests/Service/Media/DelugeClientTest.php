<?php

namespace App\Tests\Service\Media;

use App\Service\ConfigService;
use App\Service\Media\DelugeClient;
use App\Service\Media\ServiceHealthCache;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[AllowMockObjectsWithoutExpectations]
class DelugeClientTest extends TestCase
{
    private function makeClient(): DelugeClient
    {
        $config = $this->createMock(ConfigService::class);
        return new DelugeClient($config, new NullLogger(), new ServiceHealthCache(new ArrayAdapter()));
    }

    private function invokeStatic(string $method, mixed ...$args): mixed
    {
        $m = (new \ReflectionClass(DelugeClient::class))->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke(null, ...$args);
    }

    /**
     * deluge-web answers HTTP 200 for EVERYTHING — the real outcome lives in
     * the JSON-RPC envelope. parseRpcBody() must return the result on
     * success and a structured error otherwise (including malformed JSON,
     * which happens when a reverse proxy serves an HTML error page).
     *
     * @return iterable<string, array{string, mixed, ?string}>
     */
    public static function rpcBodies(): iterable
    {
        yield 'success with result'  => ['{"result": "2.1.1", "error": null, "id": 1}', '2.1.1', null];
        yield 'success null result'  => ['{"result": null, "error": null, "id": 2}', null, null];
        yield 'auth error (code 1)'  => ['{"result": null, "error": {"message": "Not authenticated", "code": 1}, "id": 3}', null, 'Not authenticated'];
        yield 'plugin missing'       => ['{"result": null, "error": {"message": "Unknown method", "code": 2}, "id": 4}', null, 'Unknown method'];
        yield 'malformed body'       => ['<html>proxy error</html>', null, 'malformed JSON-RPC response'];
    }

    #[DataProvider('rpcBodies')]
    public function testParseRpcBody(string $body, mixed $expectedResult, ?string $expectedError): void
    {
        $parsed = $this->invokeStatic('parseRpcBody', $body);
        $this->assertSame($expectedResult, $parsed['result']);
        if ($expectedError === null) {
            $this->assertNull($parsed['error']);
        } else {
            $this->assertSame($expectedError, $parsed['error']['message']);
        }
    }

    public function testParseRpcBodyExposesErrorCodeForReloginDetection(): void
    {
        $parsed = $this->invokeStatic('parseRpcBody', '{"result": null, "error": {"message": "Not authenticated", "code": 1}, "id": 9}');
        $this->assertSame(1, $parsed['error']['code']);
    }

    /**
     * deluge-web sets `_session_id` on auth.login. We must echo it back as a
     * ready-to-send "name=value" pair, and return null when login failed
     * (wrong password → result false, no cookie).
     *
     * @return iterable<string, array{string, ?string}>
     */
    public static function setCookieHeaders(): iterable
    {
        yield 'session cookie' => [
            "HTTP/1.1 200 OK\r\nSet-Cookie: _session_id=abcDEF123; Expires=...; Path=/json\r\n\r\n",
            '_session_id=abcDEF123',
        ];
        yield 'no cookie (bad password)' => [
            "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n",
            null,
        ];
    }

    #[DataProvider('setCookieHeaders')]
    public function testExtractSessionCookie(string $rawHeaders, ?string $expected): void
    {
        $this->assertSame($expected, $this->invokeStatic('extractSessionCookie', $rawHeaders));
    }
}
