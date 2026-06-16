# Latency-aware Service Health Chips Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make each dashboard "Services health" chip show a five-state status (up / slow / very_slow / degraded / down) plus a response-time reading, instead of a binary Up/Down.

**Architecture:** Add a new `HealthService::statusFor()` that times a live ping and classifies it; refactor the existing `isHealthy()` to delegate to it (so the topbar + `/api/health/services` boolean contract is unchanged). The dashboard controller, template, CSS and translations consume the richer status. Degraded = a stale verdict served from the cross-request circuit breaker without a live probe.

**Tech Stack:** PHP 8 / Symfony, Twig, PHPUnit. No local PHP — tests run in the `prismarr` Docker container.

---

## Conventions for this plan

- **Run all commands from the repo root** `C:\workspace\Prismarr` unless stated.
- **Test command** (the `prismarr` container must be running — `docker compose up -d` if not):

  ```bash
  docker compose exec -T prismarr php bin/phpunit --filter <TestNameOrClass>
  ```

  The Symfony app root inside the container is `/var/www/html` (where `bin/phpunit` lives), which is the default working dir for `exec`.
- The chip status struct used everywhere is: `['status' => 'up'|'slow'|'very_slow'|'degraded'|'down'|null, 'latencyMs' => ?int]`. `status === null` means "not configured / unknown" → caller drops the chip.

## File Structure

- Modify `symfony/src/Service/HealthService.php` — add `statusFor()` + `classifyLatency()`, refactor `isHealthy()`, repoint the cache.
- Create `symfony/tests/Service/HealthServiceStatusTest.php` — unit tests for the new probe + `isHealthy()` regression.
- Modify `symfony/src/Controller/DashboardController.php` — `servicesHealth()` uses `statusFor()` and emits the richer chip shape.
- Modify `symfony/tests/Controller/DashboardControllerTest.php` — update to the new chip shape.
- Modify `symfony/templates/dashboard/_health.html.twig` — render status word + latency.
- Modify `symfony/templates/dashboard/index.html.twig` — add five dot-color CSS classes.
- Modify `symfony/translations/messages+intl-icu.en.yaml` and `messages+intl-icu.fr.yaml` — new `dashboard.health.status_*` keys.
- Modify `CHANGELOG.md`.

---

## Task 1: Latency classifier (pure function)

**Files:**
- Modify: `symfony/src/Service/HealthService.php`
- Test: `symfony/tests/Service/HealthServiceStatusTest.php` (create)

- [ ] **Step 1: Write the failing test**

Create `symfony/tests/Service/HealthServiceStatusTest.php`:

```php
<?php

namespace App\Tests\Service;

use App\Service\HealthService;
use PHPUnit\Framework\TestCase;

class HealthServiceStatusTest extends TestCase
{
    public function testClassifyLatencyBoundaries(): void
    {
        self::assertSame('up',        HealthService::classifyLatency(0));
        self::assertSame('up',        HealthService::classifyLatency(750));
        self::assertSame('slow',      HealthService::classifyLatency(751));
        self::assertSame('slow',      HealthService::classifyLatency(2000));
        self::assertSame('very_slow', HealthService::classifyLatency(2001));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T prismarr php bin/phpunit --filter testClassifyLatencyBoundaries`
Expected: FAIL — `Call to undefined method App\Service\HealthService::classifyLatency()`.

- [ ] **Step 3: Add the classifier**

In `symfony/src/Service/HealthService.php`, add this method (place it just above `isHealthy()`, after the class properties):

```php
    /**
     * Bucket a successful ping's round-trip (ms) into a latency status.
     * Thresholds: <=750 up, <=2000 slow, otherwise very_slow. The 2000 edge
     * counts as slow so the boundary is unambiguous.
     */
    public static function classifyLatency(int $ms): string
    {
        return match (true) {
            $ms <= 750  => 'up',
            $ms <= 2000 => 'slow',
            default     => 'very_slow',
        };
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec -T prismarr php bin/phpunit --filter testClassifyLatencyBoundaries`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add symfony/src/Service/HealthService.php symfony/tests/Service/HealthServiceStatusTest.php
git commit -m "feat(health): add latency classifier for service status"
```

---

## Task 2: `statusFor()` probe + `isHealthy()` delegation

**Files:**
- Modify: `symfony/src/Service/HealthService.php` (add `statusFor()`, `remember()`; refactor `isHealthy()`; repoint cache in `invalidate()`)
- Test: `symfony/tests/Service/HealthServiceStatusTest.php`

- [ ] **Step 1: Write the failing tests**

Append these imports to the top `use` block of `symfony/tests/Service/HealthServiceStatusTest.php`:

```php
use App\Service\ConfigService;
use App\Service\Media\JellyseerrClient;
use App\Service\Media\ProwlarrClient;
use App\Service\Media\QBittorrentClient;
use App\Service\Media\RadarrClient;
use App\Service\Media\ServiceHealthCache;
use App\Service\Media\SonarrClient;
use App\Service\Media\TmdbClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
```

Add the `#[AllowMockObjectsWithoutExpectations]` attribute to the class (above `class HealthServiceStatusTest`), and add these members + tests inside the class:

```php
    /**
     * Build a HealthService with a real ProwlarrClient mock so we can drive
     * ping(), a real ServiceHealthCache (ArrayAdapter-backed) for the breaker,
     * and config=null so isConfigured() is skipped — we exercise the probe and
     * breaker paths directly. Prowlarr is single-instance (no slug, no
     * ServiceInstanceProvider needed), which keeps the wiring minimal.
     */
    private function make(?ProwlarrClient $prowlarr, ?ServiceHealthCache $cache, ?ConfigService $config = null): HealthService
    {
        return new HealthService(
            $this->createMock(RadarrClient::class),
            $this->createMock(SonarrClient::class),
            $prowlarr ?? $this->createMock(ProwlarrClient::class),
            $this->createMock(JellyseerrClient::class),
            $this->createMock(QBittorrentClient::class),
            $this->createMock(TmdbClient::class),
            $config,
            $cache,
        );
    }

    public function testStatusUpWhenPingSucceedsQuickly(): void
    {
        $prowlarr = $this->createMock(ProwlarrClient::class);
        $prowlarr->method('ping')->willReturn(true);

        $result = $this->make($prowlarr, null)->statusFor('prowlarr');

        self::assertSame('up', $result['status']); // mock ping returns instantly
        self::assertIsInt($result['latencyMs']);
        self::assertGreaterThanOrEqual(0, $result['latencyMs']);
    }

    public function testStatusDownWhenPingFails(): void
    {
        $prowlarr = $this->createMock(ProwlarrClient::class);
        $prowlarr->method('ping')->willReturn(false);

        $result = $this->make($prowlarr, null)->statusFor('prowlarr');

        self::assertSame('down', $result['status']);
        self::assertNull($result['latencyMs']);
    }

    public function testStatusDegradedWhenBreakerOpen(): void
    {
        $cache = new ServiceHealthCache(new ArrayAdapter());
        $cache->markDown('prowlarr');

        // A degraded verdict must come from the breaker WITHOUT a live ping.
        $prowlarr = $this->createMock(ProwlarrClient::class);
        $prowlarr->expects(self::never())->method('ping');

        $result = $this->make($prowlarr, $cache)->statusFor('prowlarr');

        self::assertSame('degraded', $result['status']);
        self::assertNull($result['latencyMs']);
    }

    public function testStatusNullWhenNotConfigured(): void
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('has')->willReturn(false);   // no prowlarr_url / api_key
        $config->method('get')->willReturn(null);

        $result = $this->make(null, null, $config)->statusFor('prowlarr');

        self::assertNull($result['status']);
        self::assertNull($result['latencyMs']);
    }

    public function testIsHealthyStillProjectsToBool(): void
    {
        $up = $this->createMock(ProwlarrClient::class);
        $up->method('ping')->willReturn(true);
        self::assertTrue($this->make($up, null)->isHealthy('prowlarr'));

        $down = $this->createMock(ProwlarrClient::class);
        $down->method('ping')->willReturn(false);
        self::assertFalse($this->make($down, null)->isHealthy('prowlarr'));

        $cache = new ServiceHealthCache(new ArrayAdapter());
        $cache->markDown('prowlarr');
        self::assertFalse($this->make(null, $cache)->isHealthy('prowlarr')); // degraded -> false
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec -T prismarr php bin/phpunit --filter HealthServiceStatusTest`
Expected: FAIL — `statusFor()` is undefined (the new tests error), `testClassifyLatencyBoundaries` still passes.

- [ ] **Step 3: Add `statusFor()` + `remember()` and repoint the cache**

In `symfony/src/Service/HealthService.php`:

(a) Replace the existing cache property declaration:

```php
    /** @var array<string, array{ok: ?bool, at: int}> */
    private array $cache = [];
```

with:

```php
    /** @var array<string, array{status: array{status: ?string, latencyMs: ?int}, at: int}> */
    private array $statusCache = [];
```

(b) Replace the entire body of `isHealthy()` (everything between its `{` and `}`) with the delegation:

```php
    public function isHealthy(string $service, ?string $instanceSlug = null): ?bool
    {
        return match ($this->statusFor($service, $instanceSlug)['status']) {
            'up', 'slow', 'very_slow' => true,
            'down', 'degraded'        => false,
            default                   => null, // not configured / unknown
        };
    }
```

(c) Add `statusFor()` and `remember()` immediately after `isHealthy()` (before `pingFor()`):

```php
    /**
     * Richer health probe for the dashboard widget: returns a status word plus
     * a latency reading. Cached CACHE_TTL seconds in-process, same as the
     * legacy bool path (which now delegates here).
     *
     *  - not configured        → ['status' => null, ...]  (caller drops the chip)
     *  - circuit breaker open   → 'degraded' (stale cached verdict, no live probe)
     *  - live ping fails        → 'down' (timeout / refused / auth all surface here)
     *  - live ping succeeds     → up / slow / very_slow by round-trip latency
     *
     * @return array{status: ?string, latencyMs: ?int}
     */
    public function statusFor(string $service, ?string $instanceSlug = null): array
    {
        $key = $instanceSlug !== null ? $service . ':' . $instanceSlug : $service;
        $now = time();
        if (isset($this->statusCache[$key]) && ($now - $this->statusCache[$key]['at']) < self::CACHE_TTL) {
            return $this->statusCache[$key]['status'];
        }

        // Unconfigured services are never pinged (issue #9). Skipped when no
        // ConfigService is wired (legacy test paths).
        if ($this->config !== null && !$this->isConfigured($service)) {
            return $this->remember($key, ['status' => null, 'latencyMs' => null], $now);
        }

        // Circuit breaker open → we'd serve a stale cached-down verdict without
        // a live probe. That's "cached stale data", not a freshly confirmed
        // outage → degraded. radarr/sonarr mark the breaker WITH the instance
        // slug, so per-instance degraded detection lines up here.
        if ($this->serviceHealthCache?->isDown($service, $instanceSlug)) {
            return $this->remember($key, ['status' => 'degraded', 'latencyMs' => null], $now);
        }

        // Live probe — time it with a monotonic clock.
        $start = hrtime(true);
        try {
            $ok = $this->pingFor($service, $instanceSlug);
        } catch (\Throwable) {
            $ok = false;
        }
        $latencyMs = (int) round((hrtime(true) - $start) / 1e6);

        if ($ok === null) {
            return $this->remember($key, ['status' => null, 'latencyMs' => null], $now);
        }
        if ($ok === false) {
            return $this->remember($key, ['status' => 'down', 'latencyMs' => null], $now);
        }

        return $this->remember(
            $key,
            ['status' => self::classifyLatency($latencyMs), 'latencyMs' => $latencyMs],
            $now,
        );
    }

    /**
     * @param array{status: ?string, latencyMs: ?int} $status
     * @return array{status: ?string, latencyMs: ?int}
     */
    private function remember(string $key, array $status, int $now): array
    {
        $this->statusCache[$key] = ['status' => $status, 'at' => $now];
        return $status;
    }
```

(d) In `invalidate()`, repoint the in-process cache from `$this->cache` to `$this->statusCache`:

- Replace `$this->cache = [];` with `$this->statusCache = [];`
- Replace `unset($this->cache[$service]);` with `unset($this->statusCache[$service]);`

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec -T prismarr php bin/phpunit --filter HealthServiceStatusTest`
Expected: PASS (all 6 tests).

Also confirm no regression in the existing diagnose test:
Run: `docker compose exec -T prismarr php bin/phpunit --filter HealthServiceDiagnoseTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add symfony/src/Service/HealthService.php symfony/tests/Service/HealthServiceStatusTest.php
git commit -m "feat(health): add statusFor() latency probe, delegate isHealthy()"
```

---

## Task 3: Dashboard controller emits richer chips

**Files:**
- Modify: `symfony/src/Controller/DashboardController.php:547-575` (the `servicesHealth()` method)
- Test: `symfony/tests/Controller/DashboardControllerTest.php`

- [ ] **Step 1: Update the failing test**

Replace the body of `testServicesHealthExpandsOneChipPerInstance()` in `symfony/tests/Controller/DashboardControllerTest.php` so the health mock returns status structs and the assertions expect the new chip shape. Replace the `$health` setup block:

```php
        $health = $this->createMock(HealthService::class);
        $health->method('isHealthy')->willReturnCallback(
            fn(string $service, ?string $slug = null): ?bool => match (true) {
                $service === 'radarr' && $slug === 'radarr-1'  => true,
                $service === 'radarr' && $slug === 'radarr-4k' => false,
                $service === 'sonarr' && $slug === 'sonarr-1'  => true,
                $service === 'qbittorrent'                     => true,
                default                                        => null, // prowlarr/jellyseerr/tmdb not configured
            }
        );
```

with:

```php
        $health = $this->createMock(HealthService::class);
        $health->method('statusFor')->willReturnCallback(
            fn(string $service, ?string $slug = null): array => match (true) {
                $service === 'radarr' && $slug === 'radarr-1'  => ['status' => 'up',   'latencyMs' => 120],
                $service === 'radarr' && $slug === 'radarr-4k' => ['status' => 'down', 'latencyMs' => null],
                $service === 'sonarr' && $slug === 'sonarr-1'  => ['status' => 'slow', 'latencyMs' => 1500],
                $service === 'qbittorrent'                     => ['status' => 'up',   'latencyMs' => 40],
                default                                        => ['status' => null,   'latencyMs' => null], // prowlarr/jellyseerr/tmdb not configured
            }
        );
```

And replace the four assertion lines at the end:

```php
        self::assertSame(['id' => 'radarr', 'name' => 'Radarr 1080p', 'state' => true], $chips[0]);
        self::assertSame(['id' => 'radarr', 'name' => 'Radarr 4K', 'state' => false], $chips[1]);
        self::assertSame(['id' => 'sonarr', 'name' => 'Sonarr', 'state' => true], $chips[2]);
        self::assertSame(['id' => 'qbittorrent', 'name' => 'qBittorrent', 'state' => true], $chips[3]);
```

with:

```php
        self::assertSame(['id' => 'radarr', 'name' => 'Radarr 1080p', 'status' => 'up',   'latencyMs' => 120],  $chips[0]);
        self::assertSame(['id' => 'radarr', 'name' => 'Radarr 4K',    'status' => 'down', 'latencyMs' => null], $chips[1]);
        self::assertSame(['id' => 'sonarr', 'name' => 'Sonarr',       'status' => 'slow', 'latencyMs' => 1500], $chips[2]);
        self::assertSame(['id' => 'qbittorrent', 'name' => 'qBittorrent', 'status' => 'up', 'latencyMs' => 40], $chips[3]);
```

(Also update the docblock `@var` on the `$chips` line from `array{id: string, name: string, state: bool}` to `array{id: string, name: string, status: string, latencyMs: ?int}`.)

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T prismarr php bin/phpunit --filter testServicesHealthExpandsOneChipPerInstance`
Expected: FAIL — chips still have the old `state` shape (or `statusFor` is not yet called).

- [ ] **Step 3: Update `servicesHealth()`**

In `symfony/src/Controller/DashboardController.php`, replace the entire body of `servicesHealth()` (keep its docblock) with:

```php
    private function servicesHealth(): array
    {
        $chips = [];

        foreach ([ServiceInstance::TYPE_RADARR, ServiceInstance::TYPE_SONARR] as $type) {
            foreach ($this->instances->getEnabled($type) as $inst) {
                try {
                    $s = $this->health->statusFor($type, $inst->getSlug());
                } catch (\Throwable) {
                    $s = ['status' => 'down', 'latencyMs' => null];
                }
                if ($s['status'] === null) continue; // instance has no credentials yet
                $chips[] = ['id' => $type, 'name' => $inst->getName(), 'status' => $s['status'], 'latencyMs' => $s['latencyMs']];
            }
        }

        $labels = ['prowlarr' => 'Prowlarr', 'jellyseerr' => 'Seerr', 'qbittorrent' => 'qBittorrent', 'tmdb' => 'TMDb', 'tautulli' => 'Tautulli'];
        foreach ($labels as $service => $label) {
            try {
                $s = $this->health->statusFor($service);
            } catch (\Throwable) {
                $s = ['status' => null, 'latencyMs' => null];
            }
            if ($s['status'] === null) continue; // not configured / disabled
            $chips[] = ['id' => $service, 'name' => $label, 'status' => $s['status'], 'latencyMs' => $s['latencyMs']];
        }

        return $chips;
    }
```

Also update the method's docblock `@return` line from
`@return list<array{id: string, name: string, state: bool}>` to
`@return list<array{id: string, name: string, status: string, latencyMs: ?int}>`.

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec -T prismarr php bin/phpunit --filter testServicesHealthExpandsOneChipPerInstance`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add symfony/src/Controller/DashboardController.php symfony/tests/Controller/DashboardControllerTest.php
git commit -m "feat(dashboard): build health chips from statusFor (status + latency)"
```

---

## Task 4: Translations

**Files:**
- Modify: `symfony/translations/messages+intl-icu.en.yaml` (dashboard.health block, after `status_unk` ~line 399)
- Modify: `symfony/translations/messages+intl-icu.fr.yaml` (dashboard.health block, after `status_unk` ~line 398)

There is no test for this task; it is verified by the template render in Task 5 and the full suite in Task 7. Keep the existing `status_ok` / `status_ko` / `status_unk` keys — the topbar still uses them.

- [ ] **Step 1: Add English keys**

In `symfony/translations/messages+intl-icu.en.yaml`, find the **dashboard** `health:` block (the one with `settings_cta: 'Settings →'` and `status_ok: Up`). Immediately after its `status_unk: Not configured` line, add (matching the 4-space indentation of `status_unk`):

```yaml
    status_up: Up
    status_slow: Slow
    status_very_slow: Very slow
    status_degraded: Degraded
    status_down: Down
```

- [ ] **Step 2: Add French keys**

In `symfony/translations/messages+intl-icu.fr.yaml`, find the matching dashboard `health:` block (with `settings_cta: 'Paramètres →'` and `status_unk: Non configuré`). Immediately after its `status_unk: Non configuré` line, add:

```yaml
    status_up: Opérationnel
    status_slow: Lent
    status_very_slow: Très lent
    status_degraded: Dégradé
    status_down: Injoignable
```

- [ ] **Step 3: Verify YAML is valid**

Run: `docker compose exec -T prismarr php bin/console lint:yaml translations/messages+intl-icu.en.yaml translations/messages+intl-icu.fr.yaml`
Expected: both files reported `OK`.

- [ ] **Step 4: Commit**

```bash
git add symfony/translations/messages+intl-icu.en.yaml symfony/translations/messages+intl-icu.fr.yaml
git commit -m "i18n: add latency-aware service status labels (en/fr)"
```

---

## Task 5: Template renders status + latency

**Files:**
- Modify: `symfony/templates/dashboard/_health.html.twig`

- [ ] **Step 1: Update the chip loop**

In `symfony/templates/dashboard/_health.html.twig`, the `service_colors` map and the empty-state check stay as-is. Add a status→dot-class map next to `service_colors` (inside the `{% else %}` branch, right after the `service_colors` `{% set %}` block):

```twig
    {% set status_dot = {
      up:        'is-up',
      slow:      'is-slow',
      very_slow: 'is-very-slow',
      degraded:  'is-degraded',
      down:      'is-down',
    } %}
```

Then replace the existing `{% for chip in services_health %}` … `{% endfor %}` block with:

```twig
    {% for chip in services_health %}
      <div class="service-chip">
        <span class="service-chip-dot {{ status_dot[chip.status]|default('is-unk') }}"></span>
        <div class="flex-fill">
          <div class="service-chip-name" style="color: {{ service_colors[chip.id]|default('#888') }};">{{ chip.name }}</div>
          <div class="service-chip-state">
            {{ ('dashboard.health.status_' ~ chip.status)|trans }}{% if chip.latencyMs is not null %} · {{ chip.latencyMs }} ms{% endif %}
          </div>
        </div>
      </div>
    {% endfor %}
```

- [ ] **Step 2: Verify Twig template is valid**

Run: `docker compose exec -T prismarr php bin/console lint:twig templates/dashboard/_health.html.twig`
Expected: `OK in templates/dashboard/_health.html.twig`.

- [ ] **Step 3: Commit**

```bash
git add symfony/templates/dashboard/_health.html.twig
git commit -m "feat(dashboard): render service status word + latency in health chips"
```

---

## Task 6: Five dot colors (CSS)

**Files:**
- Modify: `symfony/templates/dashboard/index.html.twig:440-443` (the `.service-chip-dot` rules)

- [ ] **Step 1: Add the new dot-color classes**

In `symfony/templates/dashboard/index.html.twig`, find these lines:

```css
  .service-chip-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
  .service-chip-dot.is-ok { background: #22c55e; box-shadow: 0 0 8px rgba(34,197,94,.55); }
  .service-chip-dot.is-ko { background: #ef4444; }
  .service-chip-dot.is-unk { background: #6b7280; }
```

Immediately after the `.is-unk` line, add the five status colors (keep the existing `is-ok`/`is-ko`/`is-unk` lines as-is):

```css
  .service-chip-dot.is-up { background: #22c55e; box-shadow: 0 0 8px rgba(34,197,94,.55); }
  .service-chip-dot.is-slow { background: #f59e0b; box-shadow: 0 0 8px rgba(245,158,11,.5); }
  .service-chip-dot.is-very-slow { background: #f97316; box-shadow: 0 0 8px rgba(249,115,22,.5); }
  .service-chip-dot.is-degraded { background: #94a3b8; }
  .service-chip-dot.is-down { background: #ef4444; }
```

- [ ] **Step 2: Verify Twig template is valid**

Run: `docker compose exec -T prismarr php bin/console lint:twig templates/dashboard/index.html.twig`
Expected: `OK in templates/dashboard/index.html.twig`.

- [ ] **Step 3: Commit**

```bash
git add symfony/templates/dashboard/index.html.twig
git commit -m "style(dashboard): add five-state dot colors for health chips"
```

---

## Task 7: Changelog + full suite + wrap-up

**Files:**
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Read the changelog head**

Read the top ~30 lines of `CHANGELOG.md` to match its format and find the right (Unreleased / current) section.

- [ ] **Step 2: Add a changelog entry**

Under the most recent unreleased/current section (following the file's existing bullet style), add:

```markdown
- Dashboard "Services health" chips now show a five-state status (up / slow / very_slow / degraded / down) with a response-time reading, instead of a binary Up/Down. Degraded surfaces a stale circuit-breaker verdict; latency thresholds are 750 ms / 2000 ms.
```

- [ ] **Step 3: Run the full test suite**

Run: `docker compose exec -T prismarr php bin/phpunit`
Expected: the whole suite passes (green). Pay attention to `HealthServiceStatusTest`, `HealthServiceDiagnoseTest`, `DashboardControllerTest`, and `ControllersSmokeTest`.

- [ ] **Step 4: Commit**

```bash
git add CHANGELOG.md
git commit -m "docs: changelog for latency-aware service health chips"
```

- [ ] **Step 5: Verify in the running app (manual)**

Open the dashboard (`/tableau-de-bord`) in a browser against the running container and confirm the health card shows each service with a status word and `· N ms`, with dot colors matching the status. (Optional but recommended before opening a PR.)

---

## Notes / risks

- **Latency in tests is deterministic** only for the down/degraded/null paths and the up-path (mock ping returns instantly → always `up`). The slow/very_slow buckets are covered purely by `classifyLatency()` (Task 1), not by timing a real slow ping — intentional, to avoid `sleep()` in tests.
- **`isHealthy()` behavior is unchanged** for the topbar and `/api/health/services`: up/slow/very_slow→true, down/degraded→false, null→null. A breaker-open service already returned `false` there before this change.
- **`statusFor()` does a live HTTP ping** when the breaker is closed (same cost as the old `isHealthy()` first call), 10 s-cached in-process and re-fetched by the widget's 30 s poll — no new load profile.
