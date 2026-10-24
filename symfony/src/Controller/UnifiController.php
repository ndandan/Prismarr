<?php

namespace App\Controller;

use App\Dashboard\NetworkUsageChart;
use App\Dashboard\SpeedtestChart;
use App\Service\HealthService;
use App\Service\Unifi\UnifiHistoryReader;
use App\Service\Unifi\UnifiInfraReader;
use App\Service\Unifi\UnifiLiveReader;
use App\Service\Unifi\UnifiMapReader;
use App\Service\Unifi\UnifiSparkReader;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin-only Network tab. WAN addresses, per-client traffic and reservation
 * detail are not for regular users, so ROLE_ADMIN gates the whole controller —
 * the same stance the dashboard's network widget takes.
 *
 * Four panels, three cadences, one endpoint hit per cycle no matter how many
 * panels consume it (see the readers in App\Service\Unifi) — the topology map
 * shares the live group's 10 s cadence. The shell renders with no upstream
 * call at all, so first paint never waits on the console. One further
 * fragment, a selected client's sparkline, is fetched on demand, not polled.
 *
 * Every action is read-only and every action answers 200. A monitoring page
 * that 500s because the monitored thing is sick is worse than useless.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/unifi', name: 'app_unifi_')]
class UnifiController extends AbstractController
{
    /** Group name → template. Also the whitelist for both fragment routes. */
    private const PANELS = [
        'live'    => 'unifi/_live.html.twig',
        'infra'   => 'unifi/_infra.html.twig',
        'history' => 'unifi/_history.html.twig',
        'map'     => 'unifi/_map.html.twig',
    ];

    public function __construct(
        private readonly UnifiLiveReader $live,
        private readonly UnifiInfraReader $infra,
        private readonly UnifiHistoryReader $history,
        private readonly UnifiMapReader $map,
        private readonly UnifiSparkReader $spark,
        private readonly HealthService $health,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Page shell only — deliberately touches no reader, so the page paints
     * immediately and each region hydrates from its own fragment after paint.
     */
    #[Route('', name: 'index')]
    public function index(): Response
    {
        return $this->render('unifi/index.html.twig');
    }

    /**
     * Batch fragment fetch for the page poller: `?p=live,infra` → a
     * {group: html} JSON map. Declared BEFORE the {panel} route because
     * `/unifi/api/panels` would otherwise match it.
     *
     * A group that renders empty — or fails to render at all — is omitted from
     * the map rather than sent as "", so the client leaves that region at its
     * last-good content — the same contract as the dashboard's /widgets route.
     */
    #[Route('/api/panels', name: 'panels')]
    public function panels(Request $request): Response
    {
        set_time_limit(60);

        $names = array_filter(array_map('trim', explode(',', (string) $request->query->get('p', ''))));
        $out = [];
        foreach (array_unique($names) as $name) {
            if (!isset(self::PANELS[$name])) continue;
            // Per group: renderPanel() contains reader failures, but a Twig
            // error in the template itself would otherwise 500 the whole
            // batch — and the map shares live's tick, so one bad render would
            // freeze both. Omitted = last-good, like an empty render.
            try {
                $html = (string) $this->renderPanel($name)->getContent();
            } catch (\Throwable $e) {
                $this->logger->warning('UniFi panel {panel} failed to render: {message}', [
                    'panel'   => $name,
                    'message' => $e->getMessage(),
                ]);
                continue;
            }
            if (trim($html) === '') continue;
            $out[$name] = $html;
        }

        return $this->json($out);
    }

    /**
     * One client's last-hour sparkline as an HTML fragment, fetched when the
     * admin selects a client on the map. Not a panel group: it is per-id, never
     * batched and never polled, so it stays out of PANELS and out of the
     * `panel` requirement. Declared BEFORE `/api/{panel}` so a later loosening
     * of that requirement can't shadow it.
     *
     * The requirement is a MAC as the map names it — 12 lowercase hex digits,
     * no separators — because the id ends up in a report query's POST body.
     * UnifiSparkReader re-validates; this is the first of two gates.
     *
     * An empty body is the whole "nothing to show" answer — unconfigured, no
     * samples, or the reader threw — and is still a 200: the map JS turns it
     * into a note in the detail panel, and a 404/500 would be noise for what
     * is an ordinary state. A null from the reader returns here, before Twig
     * is asked to render nothing.
     */
    #[Route('/api/spark/{id}', name: 'spark', requirements: ['id' => '[0-9a-f]{12}'])]
    public function spark(string $id): Response
    {
        if (!$this->health->isConfigured('unifi')) {
            return new Response('');
        }
        set_time_limit(60);

        try {
            $rows = $this->spark->read($id);
        } catch (\Throwable $e) {
            // Never propagate: this is a monitoring page.
            $this->logger->warning('UniFi sparkline {id} failed: {message}', [
                'id'      => $id,
                'message' => $e->getMessage(),
            ]);
            return new Response('');
        }
        if ($rows === null) {
            return new Response('');
        }

        // 'H:i': the window is one hour, so a date would only be noise. The
        // template renders '' itself if the geometry comes back null (a single
        // bucket is nothing to draw).
        return $this->render('unifi/_spark.html.twig', [
            'spark' => NetworkUsageChart::build($rows, 'H:i'),
        ]);
    }

    /** One panel as an HTML fragment, for first hydration and for retry. */
    #[Route('/api/{panel}', name: 'panel', requirements: ['panel' => 'live|infra|history|map'])]
    public function panel(string $panel): Response
    {
        if (!isset(self::PANELS[$panel])) {
            // Unreachable through routing (the requirement filters it), but the
            // method is called directly in tests and could be called from
            // another action later.
            return new Response('', Response::HTTP_NOT_FOUND);
        }
        set_time_limit(60);

        return $this->renderPanel($panel);
    }

    /**
     * Render one group. An empty body means "not applicable" and the client
     * hides the region; a rendered template with a null payload means "we
     * looked and there was nothing", which the template shows as an empty
     * state with a retry button. Those are different answers and the
     * difference is load-bearing.
     */
    private function renderPanel(string $panel): Response
    {
        if (!$this->health->isConfigured('unifi')) {
            return new Response('');
        }

        try {
            $data = match ($panel) {
                'live'    => $this->live->read(),
                'infra'   => $this->infra->read(),
                'history' => $this->history->read(),
                'map'     => $this->map->read(),
            };
        } catch (\Throwable $e) {
            // Never propagate: this is a monitoring page.
            $this->logger->warning('UniFi panel {panel} failed: {message}', [
                'panel'   => $panel,
                'message' => $e->getMessage(),
            ]);
            $data = null;
        }

        return $this->render(self::PANELS[$panel], $this->contextFor($panel, $data));
    }

    /**
     * Twig gets one variable named after the group, plus — for history — the
     * two pre-built chart geometries. Chart building lives here rather than in
     * the template so the templates stay logic-free and the geometry stays
     * unit-testable.
     *
     * @return array<string, mixed>
     */
    private function contextFor(string $panel, ?array $data): array
    {
        if ($panel !== 'history') {
            return [$panel => $data];
        }

        return [
            'history' => $data,
            // Day name + date + hour for the 7-day view; the widget's 24h chart
            // keeps the default 'H:i'. One format feeds both the per-point hover
            // labels and the two axis labels, which rules out a bare 'D': seven
            // days apart is the same weekday, so the axis read "Sat … Sat" and
            // all 24 points in a day shared one tooltip (live-verified). The date
            // disambiguates the axis; the hour makes hovering a spike informative.
            'usageChart' => NetworkUsageChart::build($data['usage7d'] ?? null, 'D j H:i'),
            'speedChart' => SpeedtestChart::build($data['speedtests'] ?? null),
        ];
    }
}
