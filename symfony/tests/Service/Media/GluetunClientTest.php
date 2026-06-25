<?php

namespace App\Tests\Service\Media;

use App\Service\ConfigService;
use App\Service\Media\GluetunClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Regression suite for the Gluetun control-server integration:
 *
 *  1. The client used to authenticate with `Authorization: Bearer <key>`, but
 *     Gluetun reads the key from the `X-API-Key` header, so any configured key
 *     would not work and the integration only worked if the authentication was
 *     set to "none".
 *  2. The endpoint fallbacks `/v1/wireguard/status` and
 *     `/v1/wireguard/portforwarded` don't exist.
 */
#[AllowMockObjectsWithoutExpectations]
class GluetunClientTest extends TestCase
{
    private function makeClient(?string $apiKey): GluetunClient
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('get')->willReturnMap([
            ['gluetun_url', 'http://gluetun:8000'],
            ['gluetun_api_key', $apiKey],
        ]);

        return new GluetunClient($config, $this->createMock(LoggerInterface::class));
    }

    private function invokePrivate(GluetunClient $client, string $method): mixed
    {
        return (new \ReflectionMethod($client, $method))->invoke($client);
    }

    public function testAuthHeaderUsesXApiKeyNotBearer(): void
    {
        $headers = $this->invokePrivate($this->makeClient('s3cret-key'), 'authHeaders');

        $this->assertSame(['X-API-Key: s3cret-key'], $headers);
        $this->assertStringNotContainsStringIgnoringCase(
            'authorization',
            implode("\n", $headers),
            'Gluetun does not accept Authorization: Bearer'
        );
    }

    public function testNoAuthHeaderWhenKeyIsBlank(): void
    {
        foreach (['', null] as $blank) {
            $headers = $this->invokePrivate($this->makeClient($blank), 'authHeaders');
            $this->assertSame([], $headers);
        }
    }

    public function testStatusPathsAreUnifiedFirstWithNoWireguardEndpoint(): void
    {
        $paths = $this->invokePrivate($this->makeClient(null), 'statusPaths');

        $this->assertSame(['/v1/vpn/status', '/v1/openvpn/status'], $paths);
        $this->assertNoWireguardPath($paths);
    }

    public function testPortPathsAreUnifiedFirstWithNoWireguardEndpoint(): void
    {
        $paths = $this->invokePrivate($this->makeClient(null), 'portPaths');

        $this->assertSame(['/v1/portforward', '/v1/openvpn/portforwarded'], $paths);
        $this->assertNoWireguardPath($paths);
    }

    private function assertNoWireguardPath(array $paths): void
    {
        foreach ($paths as $path) {
            $this->assertStringNotContainsString(
                '/wireguard/',
                $path,
                'Gluetun does not have /v1/wireguard/* control endpoints.'
            );
        }
    }
}
