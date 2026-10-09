<?php

namespace App\Tests\Service;

use App\Controller\HealthController;
use App\Service\Http\ConcurrentCurl;
use App\Tests\Support\LocalHttp;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\Container\ContainerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\LockRegistry;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Every chips() characterization case from the parent re-run through the
 * concurrent cold sweep (results must be identical, each ping issued exactly
 * once), plus the cases that prove the sweep actually overlaps.
 */
#[AllowMockObjectsWithoutExpectations]
class HealthServiceChipsConcurrentTest extends HealthServiceChipsTest
{
    /** @var list<list<string>> keys of every run() batch */
    private array $batches = [];

    protected function setUp(): void
    {
        $batches = &$this->batches;
        $this->runner = new class ($batches) extends ConcurrentCurl {
            /** @param list<list<string>> $batches */
            public function __construct(private array &$batches) {}

            public function run(array $tasks): array
            {
                $this->batches[] = array_map('strval', array_keys($tasks));
                return parent::run($tasks);
            }
        };
    }

    /** A ping that holds a real ~$ms transfer open, then reports up. */
    private static function slowPing(int $ms): \Closure
    {
        return static function () use ($ms): bool {
            ConcurrentCurl::exec(LocalHttp::blackholeHandle($ms));
            return true;
        };
    }

    public function testColdSweepOverlapsThePings(): void
    {
        $settings = ['prowlarr_url' => 'http://p', 'prowlarr_api_key' => 'k', 'tmdb_api_key' => 'k',
            'sabnzbd_url' => 'http://s', 'sabnzbd_api_key' => 'k', 'bazarr_url' => 'http://b', 'bazarr_api_key' => 'k'];
        $svc = $this->make($settings, [
            'radarr:r1' => self::slowPing(400),
            'radarr:r2' => self::slowPing(400),
            'sonarr:s1' => self::slowPing(400),
            'prowlarr'  => self::slowPing(400),
            'tmdb'      => self::slowPing(400),
            'sabnzbd'   => self::slowPing(400),
            'bazarr'    => self::slowPing(400),
        ], ['radarr' => ['r1', 'r2'], 'sonarr' => ['s1']]);

        $t     = hrtime(true);
        $chips = $svc->chips();
        $ms    = (hrtime(true) - $t) / 1e6;

        self::assertCount(7, $chips);
        // One-at-a-time this is ~2800 ms; overlapped it is ~one ping.
        self::assertLessThan(1300, $ms, "cold sweep of 7 x 400 ms pings took {$ms} ms");
        foreach ($chips as $chip) {
            // Latency is still each service's OWN round-trip, not the batch total.
            self::assertSame('up', $chip['status'], $chip['name']);
            self::assertGreaterThanOrEqual(350, $chip['latencyMs'], $chip['name']);
            self::assertLessThan(750, $chip['latencyMs'], $chip['name']);
        }
    }

    public function testOnlyPoolMissesAreProbedAndInOneBatch(): void
    {
        $pool     = new ArrayAdapter();
        $settings = ['prowlarr_url' => 'http://p', 'prowlarr_api_key' => 'k', 'tmdb_api_key' => 'k',
            'bazarr_url' => 'http://b', 'bazarr_api_key' => 'k'];

        // An earlier request already cached Prowlarr.
        $this->make($settings, ['prowlarr' => true], pool: $pool)->statusFor('prowlarr');
        $this->batches = [];

        $svc = $this->make($settings, ['radarr:r1' => true, 'sonarr:s1' => false, 'tmdb' => true, 'bazarr' => true],
            ['radarr' => ['r1'], 'sonarr' => ['s1']], pool: $pool);

        self::assertSame(
            ['radarr|Radarr R1|up', 'sonarr|Sonarr S1|down', 'prowlarr|Prowlarr|up', 'tmdb|TMDb|up', 'bazarr|Bazarr|up'],
            self::summary($svc->chips()),
        );
        // Unconfigured services ride along (they resolve to null without a
        // ping); the cached one does not.
        self::assertCount(1, $this->batches);
        self::assertContains('radarr:r1', $this->batches[0]);
        self::assertContains('sonarr:s1', $this->batches[0]);
        self::assertContains('tmdb', $this->batches[0]);
        self::assertContains('bazarr', $this->batches[0]);
        self::assertNotContains('prowlarr', $this->batches[0]);
    }

    public function testTopbarEndpointSweepsOnceForChipsAndLegacyMaps(): void
    {
        // /api/health/services builds the legacy services/instances maps AND
        // the chip list. The cold sweep must cover both in one batch; the
        // legacy pass is then served from the memo (each ping still once).
        $settings = ['prowlarr_url' => 'http://p', 'prowlarr_api_key' => 'k', 'tmdb_api_key' => 'k'];
        $health   = $this->make($settings, ['radarr:r1' => true, 'radarr:r2' => false, 'prowlarr' => true, 'tmdb' => false],
            ['radarr' => ['r1', 'r2']]);

        $controller = new HealthController();
        $checker    = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn(false);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnCallback(fn (string $id) => $id === 'security.authorization_checker' ? $checker : null);
        $controller->setContainer($container);

        self::assertNotNull($this->provider);
        $payload = json_decode((string) $controller->servicesHealth($health, $this->provider)->getContent(), true);

        self::assertCount(1, $this->batches);
        self::assertContains('radarr:r1', $this->batches[0]);
        self::assertContains('prowlarr', $this->batches[0]);
        self::assertTrue($payload['services']['prowlarr']);
        self::assertFalse($payload['services']['tmdb']);
        self::assertNull($payload['services']['jellyseerr']);
        self::assertFalse($payload['services']['radarr']); // AND of r1 (up) and r2 (down)
        self::assertSame([true, false], array_column($payload['instances']['radarr'], 'state'));
        self::assertSame(2, $payload['ok']);
        self::assertSame(4, $payload['total']);
    }

    public function testSweepWorksThroughTheProductionFilesystemPool(): void
    {
        // cache.app is a FilesystemAdapter whose get() runs callbacks under
        // LockRegistry — the sweep lock, the read-only probes and the writes
        // all nest inside it. Prove the round-trip on the real adapter.
        $dir  = sys_get_temp_dir() . '/prismarr-health-pool-' . bin2hex(random_bytes(4));
        $pool = new FilesystemAdapter('', 0, $dir);
        $settings = ['prowlarr_url' => 'http://p', 'prowlarr_api_key' => 'k', 'tmdb_api_key' => 'k'];

        try {
            $first = $this->make($settings, ['radarr:r1' => true, 'prowlarr' => false, 'tmdb' => true], ['radarr' => ['r1']], pool: $pool)->chips();
            self::assertCount(1, $this->batches);
            self::assertSame(['radarr|Radarr R1|up', 'prowlarr|Prowlarr|down', 'tmdb|TMDb|up'], self::summary($first));

            // Next request: everything pooled → no pings (mocks forbid them), no batch.
            $this->batches = [];
            $second = $this->make($settings, [], ['radarr' => ['r1']], pool: $pool)->chips();
            self::assertSame(self::summary($first), self::summary($second));
            self::assertSame($first[0]['latencyMs'], $second[0]['latencyMs']);
            self::assertSame([], $this->batches);
        } finally {
            $pool->clear();
            @rmdir($dir);
        }
    }

    public function testASecondRequestWaitsForAnInFlightSweepInsteadOfReprobing(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            self::markTestSkipped('needs pcntl/posix to run two "requests" at once');
        }
        $dir      = sys_get_temp_dir() . '/prismarr-health-pool-' . bin2hex(random_bytes(4));
        $settings = ['prowlarr_url' => 'http://p', 'prowlarr_api_key' => 'k', 'tmdb_api_key' => 'k'];

        $pid = pcntl_fork();
        if ($pid === 0) {
            // Child = request A: cold sweep with slow pings. Fresh lock-file
            // handles — inherited ones would share the parent's flock state.
            try {
                LockRegistry::setFiles(LockRegistry::setFiles([]));
                $slow = ['radarr:r1' => self::slowPing(600), 'prowlarr' => self::slowPing(600), 'tmdb' => self::slowPing(600)];
                $this->make($settings, $slow, ['radarr' => ['r1']], pool: new FilesystemAdapter('', 0, $dir))->chips();
            } finally {
                posix_kill(getmypid(), SIGKILL); // the child must never return into PHPUnit
            }
        }

        try {
            usleep(250_000); // request B arrives while A is mid-sweep
            $t     = hrtime(true);
            $chips = $this->make($settings, [], ['radarr' => ['r1']], pool: new FilesystemAdapter('', 0, $dir))->chips();
            $ms    = (hrtime(true) - $t) / 1e6;

            // B pinged nothing (mocks forbid it), ran no batch, waited for A
            // and served A's verdicts.
            self::assertSame(['radarr|Radarr R1|up', 'prowlarr|Prowlarr|up', 'tmdb|TMDb|up'], self::summary($chips));
            self::assertSame([], $this->batches);
            self::assertGreaterThan(200, $ms, "B returned after {$ms} ms — it did not wait for A's sweep");
        } finally {
            pcntl_waitpid($pid, $status);
            (new FilesystemAdapter('', 0, $dir))->clear();
            @rmdir($dir);
        }
    }

    public function testWarmCacheMakesNoBatch(): void
    {
        $pool     = new ArrayAdapter();
        $settings = ['prowlarr_url' => 'http://p', 'prowlarr_api_key' => 'k', 'tmdb_api_key' => 'k'];
        $this->make($settings, ['prowlarr' => true, 'tmdb' => true], pool: $pool)->chips();
        $this->batches = [];

        $this->make($settings, [], pool: $pool)->chips();

        self::assertSame([], $this->batches);
    }

    /**
     * Review fix: work a probe does synchronously before its transfer (the
     * SSRF guard's DNS lookup in Bazarr/Tautulli) blocks the whole multi
     * loop. Those probes now start FIRST, so the stall happens before any
     * other service's clock starts — healthy services are not charged for it.
     */
    public function testASynchronousStallInOneProbeIsNotChargedToTheOthers(): void
    {
        $settings = ['prowlarr_url' => 'http://p', 'prowlarr_api_key' => 'k', 'tmdb_api_key' => 'k',
            'bazarr_url' => 'http://b', 'bazarr_api_key' => 'k'];
        $svc = $this->make($settings, [
            'radarr:r1' => self::slowPing(300),
            'sonarr:s1' => self::slowPing(300),
            'prowlarr'  => self::slowPing(300),
            'tmdb'      => self::slowPing(300),
            'bazarr'    => static function (): bool {
                usleep(900_000); // a blocking DNS lookup before the transfer
                ConcurrentCurl::exec(LocalHttp::blackholeHandle(100));
                return true;
            },
        ], ['radarr' => ['r1'], 'sonarr' => ['s1']]);

        foreach ($svc->chips() as $chip) {
            if ($chip['name'] === 'Bazarr') {
                continue;
            }
            self::assertSame('up', $chip['status'], $chip['name'] . ' must not be marked slow by another probe\'s stall');
            self::assertLessThan(750, $chip['latencyMs'], $chip['name']);
        }
    }

    /** Review fix: a fully cached chips() call must not queue behind the sweep lock. */
    public function testAWarmChipsCallNeverTouchesTheSweepLock(): void
    {
        $keys = [];
        $pool = new class ($keys) extends ArrayAdapter {
            /** @param list<string> $keys */
            public function __construct(private array &$keys) { parent::__construct(); }

            public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
            {
                $this->keys[] = $key;

                return parent::get($key, $callback, $beta, $metadata);
            }
        };
        $settings = ['prowlarr_url' => 'http://p', 'prowlarr_api_key' => 'k', 'tmdb_api_key' => 'k'];
        $pings    = ['radarr:r1' => true, 'sonarr:s1' => true, 'prowlarr' => true, 'tmdb' => true];
        $this->make($settings, $pings, ['radarr' => ['r1'], 'sonarr' => ['s1']], pool: $pool)->chips(); // cold: sweeps
        $keys = [];

        $this->make($settings, [], ['radarr' => ['r1'], 'sonarr' => ['s1']], pool: $pool)->chips(); // warm: no ping may run

        self::assertSame([], array_values(array_filter($keys, static fn (string $k): bool => str_contains($k, 'sweep_lock'))));
    }
}
