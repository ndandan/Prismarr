<?php

namespace App\Tests\Controller;

use App\Controller\BazarrController;
use App\Service\ConfigService;
use App\Service\Media\BazarrClient;
use App\Service\Media\BazarrPosterResolver;
use App\Service\Media\BazarrSubtitleIndex;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;

/**
 * Bazarr health badge (batch 3): GET /bazarr/api/health hands Bazarr's own
 * health issues (inaccessible root folders, broken language profiles…) to
 * the badge in the Bazarr nav. `ok:false` (badge hidden) when Bazarr can't
 * be read.
 */
#[AllowMockObjectsWithoutExpectations]
class BazarrHealthApiTest extends TestCase
{
    /** @param list<array{object: string, issue: string}>|null $issues */
    private function health(?array $issues): array
    {
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->once())->method('getHealth')->willReturn($issues);

        $controller = new BazarrController(
            $client,
            $this->createMock(ConfigService::class),
            new NullLogger(),
            $this->createMock(BazarrSubtitleIndex::class),
            $this->createMock(ServiceInstanceProvider::class),
            $this->createMock(BazarrPosterResolver::class),
        );
        $controller->setContainer(new Container());

        return json_decode((string) $controller->apiHealth()->getContent(), true);
    }

    public function testPassesBazarrsIssuesThrough(): void
    {
        $issues = [['object' => '/movies', 'issue' => 'Not accessible']];

        self::assertSame(['ok' => true, 'issues' => $issues], $this->health($issues));
    }

    public function testAHealthyBazarrHasNoIssues(): void
    {
        self::assertSame(['ok' => true, 'issues' => []], $this->health([]));
    }

    public function testAnUnreadableBazarrIsNotOk(): void
    {
        self::assertSame(['ok' => false, 'issues' => []], $this->health(null));
    }
}
