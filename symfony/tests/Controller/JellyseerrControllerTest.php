<?php

namespace App\Tests\Controller;

use App\Controller\JellyseerrController;
use App\Service\ConfigService;
use App\Service\Media\JellyseerrClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Pins the contract of the /jellyseerr/api/pending-count endpoint that feeds
 * the sidebar pending-requests badge. The JS poll reads `pending` on success
 * and backs off on `error` (HTTP 500), so this endpoint must:
 *   - surface the live `pending` count from /request/count,
 *   - default to 0 when the key is absent,
 *   - answer 500 (not a misleading zero) when Jellyseerr is unreachable.
 */
#[AllowMockObjectsWithoutExpectations]
class JellyseerrControllerTest extends TestCase
{
    private function controller(JellyseerrClient $jellyseerr): JellyseerrController
    {
        $config     = $this->createMock(ConfigService::class);
        $logger     = $this->createMock(LoggerInterface::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $controller = new JellyseerrController($jellyseerr, $config, $logger, $translator);

        // AbstractController::json() reaches into the container; an empty one
        // that returns null is enough to satisfy the typed property.
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $container->method('get')->willReturn(null);
        $controller->setContainer($container);

        return $controller;
    }

    public function testPendingCountSurfacesTheLiveCount(): void
    {
        $client = $this->createMock(JellyseerrClient::class);
        $client->method('getRequestCount')->willReturn([
            'total' => 10, 'pending' => 3, 'approved' => 5, 'available' => 2,
        ]);

        /** @var JsonResponse $resp */
        $resp = $this->controller($client)->apiPendingCount();

        self::assertSame(200, $resp->getStatusCode());
        self::assertSame(['pending' => 3], json_decode((string) $resp->getContent(), true));
    }

    public function testPendingCountDefaultsToZeroWhenKeyAbsent(): void
    {
        $client = $this->createMock(JellyseerrClient::class);
        // A reachable Jellyseerr with nothing pending still returns a populated
        // object (no `pending` key here) — and no recorded error.
        $client->method('getRequestCount')->willReturn(['total' => 0, 'approved' => 0]);
        $client->method('getLastError')->willReturn(null);

        /** @var JsonResponse $resp */
        $resp = $this->controller($client)->apiPendingCount();

        self::assertSame(200, $resp->getStatusCode());
        self::assertSame(['pending' => 0], json_decode((string) $resp->getContent(), true));
    }

    public function testPendingCountReturns500WhenJellyseerrUnreachable(): void
    {
        $client = $this->createMock(JellyseerrClient::class);
        // Empty result + a recorded client error == unreachable, not "zero".
        $client->method('getRequestCount')->willReturn([]);
        $client->method('getLastError')->willReturn(['code' => 0, 'message' => 'timeout']);

        /** @var JsonResponse $resp */
        $resp = $this->controller($client)->apiPendingCount();

        self::assertSame(500, $resp->getStatusCode());
        self::assertArrayHasKey('error', json_decode((string) $resp->getContent(), true));
    }

    public function testPendingCountReturns500OnClientException(): void
    {
        $client = $this->createMock(JellyseerrClient::class);
        $client->method('getRequestCount')->willThrowException(new \RuntimeException('boom'));

        /** @var JsonResponse $resp */
        $resp = $this->controller($client)->apiPendingCount();

        self::assertSame(500, $resp->getStatusCode());
        self::assertArrayHasKey('error', json_decode((string) $resp->getContent(), true));
    }
}
