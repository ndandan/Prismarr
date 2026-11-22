<?php

namespace App\Tests\Controller;

use App\Controller\ProwlarrController;
use App\Service\ConfigService;
use App\Service\Media\ProwlarrClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Prowlarr search pickers (batch 2): /prowlarr/search forwards the chosen
 * indexer and a comma list of category ids. Anything that is not a positive
 * integer is dropped rather than sent to Prowlarr.
 */
#[AllowMockObjectsWithoutExpectations]
class ProwlarrSearchFiltersTest extends TestCase
{
    private function controller(ProwlarrClient $prowlarr): ProwlarrController
    {
        $controller = new ProwlarrController($prowlarr, $this->createMock(ConfigService::class), new NullLogger(), $this->createMock(TranslatorInterface::class));
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $controller->setContainer($container);
        return $controller;
    }

    public function testIndexerAndCategoriesReachTheClient(): void
    {
        $prowlarr = $this->createMock(ProwlarrClient::class);
        $prowlarr->expects($this->once())->method('search')
            ->with('dune', 5, 'search', 100, [2000, 100023])
            ->willReturn([]);

        $this->controller($prowlarr)->search(Request::create('/prowlarr/search', 'GET', [
            'q' => 'dune', 'indexerId' => '5', 'categories' => '2000,abc,-3,100023, 0',
        ]));
    }

    /** Review 2026-11-21 L17: the category list is capped, so one request cannot carry thousands of ids. */
    public function testTheCategoryListIsCapped(): void
    {
        $prowlarr = $this->createMock(ProwlarrClient::class);
        $prowlarr->expects($this->once())->method('search')
            ->with('dune', null, 'search', 100, range(1, ProwlarrController::MAX_SEARCH_CATEGORIES))
            ->willReturn([]);

        $this->controller($prowlarr)->search(Request::create('/prowlarr/search', 'GET', [
            'q' => 'dune', 'categories' => implode(',', range(1, 500)),
        ]));
        self::assertSame(50, ProwlarrController::MAX_SEARCH_CATEGORIES);
    }

    public function testNoPickersMeansAllIndexersAndCategories(): void
    {
        $prowlarr = $this->createMock(ProwlarrClient::class);
        $prowlarr->expects($this->once())->method('search')
            ->with('dune', null, 'search', 100, [])
            ->willReturn([]);

        $this->controller($prowlarr)->search(Request::create('/prowlarr/search', 'GET', ['q' => 'dune']));
    }
}
