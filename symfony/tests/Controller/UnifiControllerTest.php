<?php

namespace App\Tests\Controller;

use App\Controller\UnifiController;
use App\Service\ConfigService;
use App\Service\HealthService;
use App\Service\Unifi\UnifiHistoryReader;
use App\Service\Unifi\UnifiInfraReader;
use App\Service\Unifi\UnifiFetcher;
use App\Service\Unifi\UnifiLiveReader;
use App\Service\Unifi\UnifiMapReader;
use App\Service\Unifi\UnifiSparkReader;
use App\Tests\Service\Unifi\StubUnifiFetcher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

#[AllowMockObjectsWithoutExpectations]
class UnifiControllerTest extends TestCase
{
    /** Every (template, context) the mocked Twig was asked to render, in order. */
    private array $renders = [];

    /**
     * The five readers are `final` (per the plan's Global Constraints), so
     * they cannot be doubled. Real readers are built instead, each over its own
     * UnifiFetcher double — an interface, and the seam that exists precisely so
     * the readers run with no network. A stub answering null to every path makes
     * read() return null, which is the shape these tests need; a fetcher that
     * throws makes read() throw, since read() has no try/catch of its own.
     *
     * Payload shapes are Tasks 4-6's business, asserted in their own tests.
     * This test covers gating, dispatch and error containment only.
     *
     * @param list<string>          $throwOn   group names whose reader throws
     * @param array<string, ?array> $responses per-group stub payloads, keyed by
     *                                         path substring (only the spark
     *                                         tests need one — it is the only
     *                                         reader whose output the controller
     *                                         post-processes)
     */
    private function makeController(
        bool $configured = true,
        array $throwOn = [],
        array $responses = [],
    ): UnifiController {
        $this->renders = [];
        $fetcher = function (string $group) use ($throwOn, $responses): UnifiFetcher {
            if (!in_array($group, $throwOn, true)) {
                return new StubUnifiFetcher($responses[$group] ?? []);
            }
            $mock = $this->createMock(UnifiFetcher::class);
            $mock->method('fetch')->willThrowException(new \RuntimeException('upstream exploded'));
            return $mock;
        };

        $health = $this->createMock(HealthService::class);
        $health->method('isConfigured')->willReturnCallback(
            fn(string $s) => $s === 'unifi' ? $configured : false
        );

        // Render the template name back out instead of running real Twig: this
        // test covers gating, dispatch and error containment, not markup. Tasks
        // 8-11 own the templates and Task 12 renders them for real on :beta.
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturnCallback(
            function (string $tpl, array $ctx = []): string {
                $this->renders[] = [$tpl, $ctx];
                return 'RENDERED:' . $tpl;
            }
        );

        // The map reader reads the optional plan-speed settings; none are set
        // here, which is the unconfigured-plan path and irrelevant to dispatch.
        $config = $this->createStub(ConfigService::class);
        $config->method('get')->willReturn(null);

        $controller = new UnifiController(
            new UnifiLiveReader($fetcher('live'), new NullLogger()),
            new UnifiInfraReader($fetcher('infra'), new NullLogger()),
            new UnifiHistoryReader($fetcher('history'), new NullLogger()),
            new UnifiMapReader($fetcher('map'), $config, new NullLogger()),
            new UnifiSparkReader($fetcher('spark')),
            $health,
            new NullLogger(),
        );

        // has() answers truthfully per id rather than blanket-true: json()
        // takes its serializer branch on has('serializer') and would then call
        // serialize() on the null get() returns. Only 'twig' is provided, so
        // only 'twig' exists.
        $container = $this->createMock(\Psr\Container\ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            static fn(string $id): bool => $id === 'twig'
        );
        $container->method('get')->willReturnCallback(
            fn(string $id) => $id === 'twig' ? $twig : null
        );
        $controller->setContainer($container);

        return $controller;
    }

    /**
     * #[IsGranted] is enforced by Symfony's attribute listener during HTTP
     * dispatch, not by the method body, so a directly-invoked method cannot
     * demonstrate the 403. Assert the guard is declared instead; the live 403
     * is verified end-to-end in Task 12.
     */
    public function testControllerIsGuardedByRoleAdmin(): void
    {
        $attrs = (new \ReflectionClass(UnifiController::class))
            ->getAttributes(\Symfony\Component\Security\Http\Attribute\IsGranted::class);

        $this->assertCount(1, $attrs, 'UnifiController must carry a class-level #[IsGranted]');
        $this->assertSame('ROLE_ADMIN', $attrs[0]->newInstance()->attribute);
    }

    public function testUnconfiguredConsoleAnswersEmptyNotAnError(): void
    {
        // The route guard normally redirects first; this is the belt-and-braces
        // path for a fragment fetched from an already-open tab after the admin
        // deletes the config. Empty body = "not applicable", per the poller's
        // documented contract.
        $c = $this->makeController(configured: false);
        $r = $c->panel('live');

        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame('', $r->getContent());
    }

    public function testUnknownPanelNameIsNotFoundNotAFatal(): void
    {
        $r = $this->makeController()->panel('wat');

        $this->assertSame(404, $r->getStatusCode());
    }

    public function testEachPanelRendersItsOwnTemplate(): void
    {
        $c = $this->makeController();

        $this->assertSame('RENDERED:unifi/_live.html.twig',    $c->panel('live')->getContent());
        $this->assertSame('RENDERED:unifi/_infra.html.twig',   $c->panel('infra')->getContent());
        $this->assertSame('RENDERED:unifi/_history.html.twig', $c->panel('history')->getContent());
        $this->assertSame('RENDERED:unifi/_map.html.twig',     $c->panel('map')->getContent());
    }

    public function testMapPanelDispatches(): void
    {
        // The stub answers null to everything, so the map reader returns null —
        // the template still renders (its empty state + retry), exactly as the
        // other three panels do.
        $r = $this->makeController()->panel('map');

        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame('RENDERED:unifi/_map.html.twig', $r->getContent());
        $this->assertSame(['map' => null], $this->renders[0][1], 'map gets one variable named after the group');
    }

    public function testMapPanelSurvivesReaderThrow(): void
    {
        $r = $this->makeController(throwOn: ['map'])->panel('map');

        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame('RENDERED:unifi/_map.html.twig', $r->getContent());
        $this->assertSame(['map' => null], $this->renders[0][1]);
    }

    public function testMapPanelIsEmptyWhenUnconfigured(): void
    {
        $r = $this->makeController(configured: false)->panel('map');

        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame('', $r->getContent());
        $this->assertSame([], $this->renders, 'unconfigured must not render anything');
    }

    public function testPanelRouteRequirementWhitelistsMap(): void
    {
        $route = (new \ReflectionMethod(UnifiController::class, 'panel'))
            ->getAttributes(\Symfony\Component\Routing\Attribute\Route::class)[0]->newInstance();

        $this->assertSame('live|infra|history|map', $route->requirements['panel']);
    }

    public function testAThrowingReaderStillAnswersTwoHundred(): void
    {
        // The whole point: a monitoring page must not 500 because the thing it
        // monitors misbehaved. The template renders its empty state from null.
        $r = $this->makeController(throwOn: ['live'])->panel('live');

        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame('RENDERED:unifi/_live.html.twig', $r->getContent());
    }

    public function testBatchReturnsAMapAndIgnoresUnknownNames(): void
    {
        $c = $this->makeController();
        $r = $c->panels(new Request(['p' => 'live,wat,infra']));

        $map = json_decode((string) $r->getContent(), true);
        $this->assertSame(['live', 'infra'], array_keys($map));
        $this->assertSame('RENDERED:unifi/_live.html.twig', $map['live']);
    }

    public function testBatchIncludesMap(): void
    {
        // The page poller asks for the map in the same cycle as the live group.
        $r = $this->makeController()->panels(new Request(['p' => 'live,map']));

        $map = json_decode((string) $r->getContent(), true);
        $this->assertSame(['live', 'map'], array_keys($map));
        $this->assertSame('RENDERED:unifi/_map.html.twig', $map['map']);
    }

    public function testBatchDeduplicatesAndToleratesAnEmptyQuery(): void
    {
        $c = $this->makeController();

        $map = json_decode((string) $c->panels(new Request(['p' => 'live, live ']))->getContent(), true);
        $this->assertSame(['live'], array_keys($map));

        $this->assertSame([], json_decode((string) $c->panels(new Request())->getContent(), true));
    }

    public function testIndexRendersTheShellWithoutTouchingAnyReader(): void
    {
        // First paint must not block on cURL. Readers that throw would surface
        // here if the shell called them; the shell must render regardless.
        $r = $this->makeController(throwOn: ['live', 'infra', 'history', 'map', 'spark'])->index();

        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame('RENDERED:unifi/index.html.twig', $r->getContent());
    }

    private const SPARK_ID = 'aabbccddeeff';

    public function testSparkRendersTheChartGeometryForARealSeries(): void
    {
        // Two 5-minute buckets — the fewest NetworkUsageChart::build() will draw.
        // Report `time` is epoch ms; the sparkline's hover/axis labels are 'H:i'
        // (a one-hour window needs no date).
        $t0 = 1_700_000_000;
        $c = $this->makeController(responses: ['spark' => ['report/5minutes.user' => [
            ['time' => $t0 * 1000,           'rx_bytes' => 100, 'tx_bytes' => 900],
            ['time' => ($t0 + 300) * 1000,   'rx_bytes' => 200, 'tx_bytes' => 800],
        ]]]);

        $r = $c->spark(self::SPARK_ID);

        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame('RENDERED:unifi/_spark.html.twig', $r->getContent());
        $this->assertCount(1, $this->renders);
        $spark = $this->renders[0][1]['spark'];
        $this->assertIsArray($spark);
        $this->assertSame(date('H:i', $t0), $spark['startLabel']);
        $this->assertSame(date('H:i', $t0 + 300), $spark['endLabel']);
    }

    public function testSparkEmptyWhenNoData(): void
    {
        // The stub answers null: the reader returns null, and the controller
        // answers an empty body without asking Twig to render nothing.
        $r = $this->makeController()->spark(self::SPARK_ID);

        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame('', $r->getContent());
        $this->assertSame([], $this->renders);
    }

    public function testSparkEmptyWhenUnconfigured(): void
    {
        $r = $this->makeController(configured: false)->spark(self::SPARK_ID);

        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame('', $r->getContent());
        $this->assertSame([], $this->renders);
    }

    public function testSparkSurvivesReaderThrow(): void
    {
        // The reader is built not to throw, but the controller must not depend
        // on that: a thrown fetch still answers an empty 200, never a 500.
        $r = $this->makeController(throwOn: ['spark'])->spark(self::SPARK_ID);

        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame('', $r->getContent());
        $this->assertSame([], $this->renders);
    }

    public function testSparkRouteRequirement(): void
    {
        // Routing is the first line of defence for an id that ends up in a POST
        // body: a MAC is 12 lowercase hex digits, nothing else reaches the action.
        $route = (new \ReflectionMethod(UnifiController::class, 'spark'))
            ->getAttributes(\Symfony\Component\Routing\Attribute\Route::class)[0]->newInstance();

        $this->assertSame('/api/spark/{id}', $route->path);
        $this->assertSame('spark', $route->name);
        $this->assertSame('[0-9a-f]{12}', $route->requirements['id']);
    }

    public function testSparkIsDeclaredBeforeThePanelRoute(): void
    {
        // Routes match in declaration order. `/api/{panel}` can't match the
        // two-segment spark path today, but a future loosening of its
        // requirement must not silently shadow this one.
        $methods = array_map(
            static fn(\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(UnifiController::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        $this->assertLessThan(array_search('panel', $methods, true), array_search('spark', $methods, true));
    }
}
