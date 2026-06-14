# Library Page Caching + Concurrent Fetch — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cut the 10–15 s Radarr/Sonarr library page loads by caching the heavy library list and fetching the remaining per-page upstream calls concurrently.

**Architecture:** A new `MediaLibraryCache` service wraps the expensive `getMovies()`/`getSeries()` payload in a short (45 s) per-instance TTL cache, with write-through invalidation from the mutating actions. A new `multiGet()` method on `RadarrClient`/`SonarrClient` fetches the cheap, volatile endpoints (status, queue, indexers, health, calendar) in one `curl_multi` batch instead of sequentially. Caching lives in the controller/service layer; the clients stay HTTP wrappers.

**Tech Stack:** PHP 8 / Symfony 7, FrankenPHP worker mode, Symfony Cache (`cache.app` filesystem pool via `Symfony\Contracts\Cache\CacheInterface`), PHPUnit 13, raw cURL / `curl_multi`.

**Deviation from spec (flag for reviewer):** The spec described a single concurrent batch that *includes* the movie/series list on a cold cache miss. This plan instead fetches the list via the cache callback (a separate `getMovies()`/`getSeries()` call on a miss) and uses `multiGet` only for the cheap volatile endpoints. Rationale: peeking the contracts-cache to conditionally fold the list into the batch adds real complexity for a marginal gain that only helps the (rare) cold path. The common warm path is still a single concurrent batch + a cache hit. Cold path is 2 round trips instead of 1 — still far better than today's 5 sequential calls.

**How to run tests:** Full suite `make test` (runs `docker exec -e APP_ENV=test prismarr vendor/bin/phpunit`). Single test: `docker exec -e APP_ENV=test prismarr vendor/bin/phpunit --filter <TestMethodName>`. Lint: `make lint` (PHP) / `make lint-twig`. Pre-commit gate: `make check`.

---

## File Structure

- **Create** `symfony/src/Service/Media/MediaLibraryCache.php` — short-TTL per-instance cache for the library list + invalidation.
- **Create** `symfony/tests/Service/Media/MediaLibraryCacheTest.php` — unit tests (ArrayAdapter backend).
- **Modify** `symfony/src/Service/Media/RadarrClient.php` — add `multiGet()`, public `normalizeMovies()`, public `normalizeQueueRecords()`; refactor `getMovies()`/`getQueue()` to reuse them.
- **Modify** `symfony/src/Service/Media/SonarrClient.php` — add `multiGet()`, public `normalizeSeriesList()`, `normalizeQueueRecords()`, `normalizeCalendarEntries()`; refactor `getSeries()`/`getQueue()`/`getCalendar()` to reuse them.
- **Create** `symfony/tests/Service/Media/RadarrClientMultiGetTest.php` — circuit-breaker short-circuit unit test.
- **Modify** `symfony/src/Controller/MediaController.php` — inject `MediaLibraryCache`; rewire `films()`/`series()`; add invalidation to the movie/series write actions.
- **Modify** `symfony/tests/Controller/` (new `MediaLibraryPageTest.php`) — smoke tests proving the pages render (HTTP 200, error banner) against the seeded-unreachable instances.

---

## Task 1: `MediaLibraryCache` service

**Files:**
- Create: `symfony/src/Service/Media/MediaLibraryCache.php`
- Test: `symfony/tests/Service/Media/MediaLibraryCacheTest.php`

- [ ] **Step 1: Write the failing test**

Create `symfony/tests/Service/Media/MediaLibraryCacheTest.php`:

```php
<?php

namespace App\Tests\Service\Media;

use App\Service\Media\MediaLibraryCache;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Short-TTL per-instance cache for the heavy Radarr/Sonarr library list.
 * ArrayAdapter is used as the contracts-cache backend so we exercise the
 * real get()/delete()/expiry semantics without the filesystem pool.
 */
class MediaLibraryCacheTest extends TestCase
{
    public function testMoviesFetchesOnceThenServesFromCache(): void
    {
        $cache = new MediaLibraryCache(new ArrayAdapter());
        $calls = 0;
        $fetch = function () use (&$calls) { $calls++; return [['id' => 1]]; };

        $first  = $cache->movies('radarr-1', $fetch);
        $second = $cache->movies('radarr-1', $fetch);

        $this->assertSame([['id' => 1]], $first);
        $this->assertSame([['id' => 1]], $second);
        $this->assertSame(1, $calls, 'second call must hit the cache, not re-fetch');
    }

    public function testEmptyResultIsNotCached(): void
    {
        $cache = new MediaLibraryCache(new ArrayAdapter());
        $calls = 0;
        $fetch = function () use (&$calls) { $calls++; return []; };

        $cache->movies('radarr-1', $fetch);
        $cache->movies('radarr-1', $fetch);

        $this->assertSame(2, $calls, 'empty result must expire immediately so the next load retries');
    }

    public function testInstancesAreKeyedIndependently(): void
    {
        $cache = new MediaLibraryCache(new ArrayAdapter());

        $a = $cache->movies('radarr-1', fn() => [['id' => 1]]);
        $b = $cache->movies('radarr-4k', fn() => [['id' => 99]]);

        $this->assertSame([['id' => 1]], $a);
        $this->assertSame([['id' => 99]], $b);
    }

    public function testMoviesAndSeriesDoNotCollide(): void
    {
        $cache = new MediaLibraryCache(new ArrayAdapter());

        $movies = $cache->movies('x-1', fn() => [['id' => 1]]);
        $series = $cache->series('x-1', fn() => [['id' => 2]]);

        $this->assertSame([['id' => 1]], $movies);
        $this->assertSame([['id' => 2]], $series);
    }

    public function testInvalidateDropsTheCachedList(): void
    {
        $cache = new MediaLibraryCache(new ArrayAdapter());
        $calls = 0;
        $fetch = function () use (&$calls) { $calls++; return [['id' => 1]]; };

        $cache->movies('radarr-1', $fetch);
        $cache->invalidate('radarr', 'radarr-1');
        $cache->movies('radarr-1', $fetch);

        $this->assertSame(2, $calls, 'invalidate() must force a re-fetch on the next load');
    }

    public function testInvalidateSonarrTargetsSeriesKey(): void
    {
        $cache = new MediaLibraryCache(new ArrayAdapter());
        $movieCalls = 0;
        $seriesCalls = 0;

        $cache->movies('s-1', function () use (&$movieCalls) { $movieCalls++; return [['id' => 1]]; });
        $cache->series('s-1', function () use (&$seriesCalls) { $seriesCalls++; return [['id' => 2]]; });

        $cache->invalidate('sonarr', 's-1');

        $cache->movies('s-1', function () use (&$movieCalls) { $movieCalls++; return [['id' => 1]]; });
        $cache->series('s-1', function () use (&$seriesCalls) { $seriesCalls++; return [['id' => 2]]; });

        $this->assertSame(1, $movieCalls, 'invalidating sonarr must not drop the movies cache');
        $this->assertSame(2, $seriesCalls, 'invalidating sonarr must drop the series cache');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec -e APP_ENV=test prismarr vendor/bin/phpunit --filter MediaLibraryCacheTest`
Expected: FAIL — `Class "App\Service\Media\MediaLibraryCache" not found`.

- [ ] **Step 3: Write minimal implementation**

Create `symfony/src/Service/Media/MediaLibraryCache.php`:

```php
<?php

namespace App\Service\Media;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Short-TTL cache for the heavy Radarr/Sonarr library list.
 *
 * The library pages re-fetch and re-normalize the entire library on every
 * visit, which on a large homelab is the dominant page-load cost. The list
 * changes slowly (adds/deletes/monitor toggles), so a short window is safe
 * and write-through invalidation keeps user actions instantly visible.
 *
 * Keyed per instance slug so one Radarr instance's library never masks
 * another's. An empty result is NOT cached (expires immediately) so a
 * transient total failure isn't pinned for the whole window — mirrors
 * DashboardController's self-heal.
 */
class MediaLibraryCache
{
    /** @internal Exposed for tests; matches DashboardController::WIDGET_CACHE_TTL. */
    public const TTL = 45; // seconds

    public function __construct(private readonly CacheInterface $cache) {}

    /**
     * @param callable():array $fetch
     * @return array<mixed>
     */
    public function movies(string $slug, callable $fetch): array
    {
        return $this->fetchCached($this->key('movies', $slug), $fetch);
    }

    /**
     * @param callable():array $fetch
     * @return array<mixed>
     */
    public function series(string $slug, callable $fetch): array
    {
        return $this->fetchCached($this->key('series', $slug), $fetch);
    }

    /** Drop the cached list for an instance after a mutating action. */
    public function invalidate(string $type, string $slug): void
    {
        $kind = $type === 'sonarr' ? 'series' : 'movies';
        $this->cache->delete($this->key($kind, $slug));
    }

    /**
     * @param callable():array $fetch
     * @return array<mixed>
     */
    private function fetchCached(string $key, callable $fetch): array
    {
        return $this->cache->get($key, function (ItemInterface $item) use ($fetch) {
            $result = $fetch();
            $item->expiresAfter($result === [] ? 0 : self::TTL);
            return $result;
        });
    }

    private function key(string $kind, string $slug): string
    {
        return 'media.' . $kind . '.' . $slug;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec -e APP_ENV=test prismarr vendor/bin/phpunit --filter MediaLibraryCacheTest`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add symfony/src/Service/Media/MediaLibraryCache.php symfony/tests/Service/Media/MediaLibraryCacheTest.php
git commit -m "feat(media): add MediaLibraryCache for short-TTL library list caching"
```

---

## Task 2: `RadarrClient::normalizeQueueRecords()` + `normalizeMovies()` (extract, no behavior change)

**Files:**
- Modify: `symfony/src/Service/Media/RadarrClient.php` (`getMovies()` ~212-218, `getQueue()` ~322-349)

This extraction lets the controller normalize raw payloads coming back from `multiGet` while reusing the exact existing transforms (DRY). It changes no behavior; the existing suite must stay green.

- [ ] **Step 1: Add the public `normalizeMovies()` wrapper and refactor `getMovies()`**

Replace `getMovies()` (currently at `RadarrClient.php:212`):

```php
    public function getMovies(): array
    {
        $data = $this->get('/api/v3/movie');
        if ($data === null) return [];

        return $this->normalizeMovies($data);
    }

    /**
     * Normalize a raw `/api/v3/movie` list payload. Public so callers that
     * obtained the raw list another way (e.g. a concurrent multiGet batch)
     * can reuse the exact same transform instead of duplicating it.
     *
     * @param array<int, array<string, mixed>> $rawMovies
     * @return list<array<string, mixed>>
     */
    public function normalizeMovies(array $rawMovies): array
    {
        return array_map(fn($m) => $this->normalizeMovie($m), $rawMovies);
    }
```

- [ ] **Step 2: Add the public `normalizeQueueRecords()` and refactor `getQueue()`**

Replace `getQueue()` (currently at `RadarrClient.php:322`):

```php
    public function getQueue(): array
    {
        $data = $this->get('/api/v3/queue', ['pageSize' => 50, 'includeMovie' => 'true']);
        if ($data === null || empty($data['records'])) return [];

        return $this->normalizeQueueRecords($data['records']);
    }

    /**
     * Normalize raw queue `records` into the shape the films UI expects.
     * Public so a concurrent multiGet batch can reuse it.
     *
     * @param array<int, array<string, mixed>> $records
     * @return list<array<string, mixed>>
     */
    public function normalizeQueueRecords(array $records): array
    {
        return array_map(fn($r) => [
            'id'             => $r['id'] ?? null,
            'movieId'        => $r['movieId'] ?? null,
            'title'          => $r['movie']['title'] ?? $r['title'] ?? '—',
            'year'           => $r['movie']['year'] ?? null,
            'size'           => $r['size'] ?? 0,
            'sizeleft'       => $r['sizeleft'] ?? 0,
            'status'         => $r['status'] ?? 'unknown',
            'trackedStatus'  => $r['trackedDownloadStatus'] ?? 'ok',
            'trackedState'   => $r['trackedDownloadState'] ?? '',
            'quality'        => $r['quality']['quality']['name'] ?? '—',
            'protocol'       => $r['protocol'] ?? '—',
            'eta'            => $r['estimatedCompletionTime'] ?? null,
            'indexer'        => $r['indexer'] ?? null,
            'downloadId'     => $r['downloadId'] ?? null,
            'outputPath'     => $r['outputPath'] ?? null,
            'downloadClient' => $r['downloadClient'] ?? null,
            'statusMessages' => array_map(fn($m) => [
                'title' => $m['title'] ?? '',
                'messages' => $m['messages'] ?? [],
            ], $r['statusMessages'] ?? []),
        ], $records);
    }
```

- [ ] **Step 3: Verify no behavior change — run the existing suite**

Run: `docker exec -e APP_ENV=test prismarr vendor/bin/phpunit`
Expected: PASS — same count as before this task (pure refactor; `getMovies()`/`getQueue()` produce identical output).

- [ ] **Step 4: Lint**

Run: `make lint`
Expected: `PHP syntax OK`.

- [ ] **Step 5: Commit**

```bash
git add symfony/src/Service/Media/RadarrClient.php
git commit -m "refactor(radarr): extract public normalizeMovies/normalizeQueueRecords"
```

---

## Task 3: `RadarrClient::multiGet()`

**Files:**
- Modify: `symfony/src/Service/Media/RadarrClient.php` (add method near the other HTTP helpers, after `get()` ~1603)
- Test: `symfony/tests/Service/Media/RadarrClientMultiGetTest.php`

- [ ] **Step 1: Write the failing test (circuit-breaker short-circuit — deterministic, no network)**

Create `symfony/tests/Service/Media/RadarrClientMultiGetTest.php`:

```php
<?php

namespace App\Tests\Service\Media;

use App\Service\Media\RadarrClient;
use App\Service\Media\ServiceHealthCache;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * multiGet() concurrency wiring. The happy path (live concurrent fetch
 * returning data) needs a real upstream and is covered by the controller
 * smoke test + manual verification; here we lock the deterministic,
 * no-network contract: an open circuit breaker short-circuits the whole
 * batch to nulls without ever touching the network or the instance
 * provider.
 */
class RadarrClientMultiGetTest extends TestCase
{
    public function testOpenCircuitBreakerShortCircuitsWholeBatchToNulls(): void
    {
        $health = new ServiceHealthCache(new ArrayAdapter());
        $health->markDown('radarr'); // unkeyed: matches the default (null-slug) instance

        // The instance provider must never be consulted — the breaker check
        // happens before ensureConfig(). A real-but-unconfigured provider mock
        // proves we never reached config resolution.
        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->expects($this->never())->method('getDefault');

        $client = new RadarrClient($instances, new NullLogger(), $health);

        $result = $client->multiGet([
            'status' => ['path' => '/api/v3/system/status'],
            'queue'  => ['path' => '/api/v3/queue'],
        ]);

        $this->assertSame(['status' => null, 'queue' => null], $result);
    }

    public function testEmptyRequestListReturnsEmptyArray(): void
    {
        $health    = new ServiceHealthCache(new ArrayAdapter());
        $instances = $this->createMock(ServiceInstanceProvider::class);
        $client    = new RadarrClient($instances, new NullLogger(), $health);

        $this->assertSame([], $client->multiGet([]));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec -e APP_ENV=test prismarr vendor/bin/phpunit --filter RadarrClientMultiGetTest`
Expected: FAIL — `Call to undefined method App\Service\Media\RadarrClient::multiGet()`.

- [ ] **Step 3: Implement `multiGet()`**

Insert into `RadarrClient.php` immediately after the `get()` method (after line ~1603, before `put()`):

```php
    /**
     * Concurrent GET of several endpoints in a single curl_multi batch.
     *
     * Same per-handle semantics as get(): SSRF protocol guard, 4 s connect /
     * 8 s total timeout, NOSIGNAL, X-Api-Key + Accept headers. Returns a map
     * name => decoded array, or null for any handle that errored / returned
     * non-200. The whole batch short-circuits to nulls instantly when the
     * instance's circuit breaker is open, so a down service costs one timeout
     * window across the page instead of one per call (the old sequential
     * get() calls stacked N × 8 s).
     *
     * @param array<string, array{path: string, params?: array}> $requests
     * @return array<string, ?array>
     */
    public function multiGet(array $requests): array
    {
        $out = array_fill_keys(array_keys($requests), null);
        if ($requests === []) {
            return $out;
        }
        if ($this->health->isDown(self::SERVICE_KEY, $this->instance?->getSlug()) || $this->serviceUnavailable) {
            $this->serviceUnavailable = true;
            return $out;
        }
        $this->lastError = null;
        $this->ensureConfig();

        $mh      = curl_multi_init();
        $handles = [];
        foreach ($requests as $name => $spec) {
            $url = rtrim($this->baseUrl, '/') . $spec['path'];
            if (!empty($spec['params'])) {
                $url .= '?' . http_build_query($spec['params']);
            }
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS, // SSRF guard
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_NOSIGNAL       => 1,
                CURLOPT_HTTPHEADER     => ["X-Api-Key: {$this->apiKey}", 'Accept: application/json'],
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$name] = $ch;
        }

        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $networkError = false;
        foreach ($handles as $name => $ch) {
            $body = curl_multi_getcontent($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            if ($err !== '' || (int) $code === 0) {
                $networkError = true;
                $this->logger->warning("RadarrClient multiGet {$name} → HTTP {$code} {$err}");
                continue; // leave null
            }
            if ((int) $code !== 200) {
                $this->logger->warning("RadarrClient multiGet {$name} → HTTP {$code}");
                continue; // leave null
            }
            $decoded = json_decode((string) $body, true);
            $out[$name] = is_array($decoded) ? $decoded : null;
        }
        curl_multi_close($mh);

        // Mirror get(): a transport-level failure trips the breaker for the
        // rest of the request + the cross-request window; a clean batch clears
        // any stale down-marker.
        if ($networkError) {
            $this->serviceUnavailable = true;
            $this->health->markDown(self::SERVICE_KEY, $this->instance?->getSlug());
        } else {
            $this->health->clear(self::SERVICE_KEY, $this->instance?->getSlug());
        }

        return $out;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec -e APP_ENV=test prismarr vendor/bin/phpunit --filter RadarrClientMultiGetTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add symfony/src/Service/Media/RadarrClient.php symfony/tests/Service/Media/RadarrClientMultiGetTest.php
git commit -m "feat(radarr): add multiGet() for concurrent endpoint fetch"
```

---

## Task 4: Rewire `MediaController::films()` to use the cache + concurrent batch

**Files:**
- Modify: `symfony/src/Controller/MediaController.php` (constructor ~35-45, `films()` ~53-171)

- [ ] **Step 1: Inject `MediaLibraryCache` into the constructor**

Add the import after the other `App\Service\Media` imports (top of file, ~line 16):

```php
use App\Service\Media\MediaLibraryCache;
```

Add the constructor property (append as the last promoted property in the `__construct` list, after `$translator` ~line 44):

```php
        private readonly TranslatorInterface $translator,
        private readonly MediaLibraryCache $libraryCache,
    ) {}
```

- [ ] **Step 2: Replace the data-fetch block of `films()`**

Replace the body from the start of `films()` (line ~63, the `set_time_limit(120);` call) down to the end of the `} catch (\Throwable $e) { ... $error = true; }` block (line ~109) with:

```php
        set_time_limit(120);

        $movies = [];
        $queue  = [];
        $error  = false;

        $indexerCount = 0;
        $warnings = [];

        $instance = $this->radarr->getInstance() ?? $this->instances->getDefault(ServiceInstance::TYPE_RADARR);
        if ($instance === null) {
            throw $this->createNotFoundException('No Radarr instance configured.');
        }
        $slug = $instance->getSlug();

        try {
            // One concurrent batch for the cheap, volatile endpoints. The
            // heavy movie list is fetched separately and cached (slow-changing).
            $batch = $this->radarr->multiGet([
                'status'   => ['path' => '/api/v3/system/status'],
                'queue'    => ['path' => '/api/v3/queue', 'params' => ['pageSize' => 50, 'includeMovie' => 'true']],
                'indexers' => ['path' => '/api/v3/indexer'],
                'health'   => ['path' => '/api/v3/health'],
            ]);

            if ($batch['status'] === null) {
                $error = true;
            } else {
                $movies = $this->libraryCache->movies($slug, fn() => $this->radarr->getMovies());

                $queue = $this->radarr->normalizeQueueRecords($batch['queue']['records'] ?? []);

                $indexers = $batch['indexers'] ?? [];
                $activeIndexers = array_filter($indexers, fn($i) => ($i['enableAutomaticSearch'] ?? false) || ($i['enableInteractiveSearch'] ?? false));
                $indexerCount = count($activeIndexers);
                if ($indexerCount === 0) {
                    $warnings[] = $this->translator->trans('media.api.no_indexer');
                }

                foreach ($batch['health'] ?? [] as $h) {
                    $warnings[] = $this->translator->trans('media.api.warning_format', ['source' => $h['source'] ?? 'Radarr', 'message' => $h['message'] ?? '?']);
                }

                $blocked = array_filter($queue, fn($q) => ($q['trackedState'] ?? '') === 'importBlocked');
                if (count($blocked) > 0) {
                    $warnings[] = $this->translator->trans('media.import.blocked_warning', ['count' => count($blocked)]);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Media films failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
```

- [ ] **Step 3: Reuse the resolved `$instance` for the render `$current`**

Further down in `films()`, replace the `$current` resolution block (currently ~line 150):

```php
        $current = $this->radarr->getInstance() ?? $this->instances->getDefault(ServiceInstance::TYPE_RADARR);
        // Defensive guard. ServiceRouteGuardSubscriber should redirect long
        // before we reach this point when no Radarr instance is configured,
        // but if a worker keeps a stale binding around we'd otherwise render
        // a template that calls path() with a null slug → 500.
        if ($current === null) {
            throw $this->createNotFoundException('No Radarr instance configured.');
        }
```

with (we already resolved + guarded `$instance` at the top):

```php
        $current = $instance;
```

Leave the `?open=` deep-link block, `$filter->apply(...)`, the stats, and the `return $this->render('media/films.html.twig', [...])` call unchanged.

- [ ] **Step 4: Lint**

Run: `make lint`
Expected: `PHP syntax OK`.

- [ ] **Step 5: Run the full suite (nothing should regress yet)**

Run: `docker exec -e APP_ENV=test prismarr vendor/bin/phpunit`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add symfony/src/Controller/MediaController.php
git commit -m "perf(media): films() uses cached library + concurrent batch fetch"
```

---

## Task 5: Smoke test for the films page

**Files:**
- Create: `symfony/tests/Controller/MediaLibraryPageTest.php`

- [ ] **Step 1: Write the smoke test**

The base class seeds a default Radarr instance `radarr-1` pointing at `http://radarr.invalid:7878` (unreachable). The page must render its error state cleanly (HTTP 200) — not 500, and not hang — which exercises the real `multiGet` concurrent path against an unreachable host (connection error → marks down → `$error = true`).

Create `symfony/tests/Controller/MediaLibraryPageTest.php`:

```php
<?php

namespace App\Tests\Controller;

use App\Tests\AbstractWebTestCase;

/**
 * Smoke tests for the Radarr/Sonarr library pages after the caching +
 * concurrent-fetch rework.
 *
 * AbstractWebTestCase seeds default radarr-1 / sonarr-1 instances pointing
 * at unreachable hosts, so every request exercises the multiGet error path:
 * the concurrent batch fails to connect, status comes back null, the
 * controller sets $error = true and renders the error banner. The contract
 * we lock here is "renders cleanly (200), never 500s, never hangs" — the
 * exact behavior users hit when their *arr is briefly down.
 */
class MediaLibraryPageTest extends AbstractWebTestCase
{
    public function testFilmsPageRendersWhenRadarrUnreachable(): void
    {
        $this->client->request('GET', '/medias/radarr-1/films');

        self::assertResponseIsSuccessful();
    }

    public function testSeriesPageRendersWhenSonarrUnreachable(): void
    {
        $this->client->request('GET', '/medias/sonarr-1/series');

        self::assertResponseIsSuccessful();
    }
}
```

- [ ] **Step 2: Run test — films assertion passes, series will pass after Task 7**

Run: `docker exec -e APP_ENV=test prismarr vendor/bin/phpunit --filter testFilmsPageRendersWhenRadarrUnreachable`
Expected: PASS. (The series test is in the same file but is validated in Task 7; run it explicitly only after Task 7.)

- [ ] **Step 3: Commit**

```bash
git add symfony/tests/Controller/MediaLibraryPageTest.php
git commit -m "test(media): smoke-test films page renders when Radarr unreachable"
```

---

## Task 6: Write-through invalidation for the films write actions

**Files:**
- Modify: `symfony/src/Controller/MediaController.php` (the movie write actions: `filmMonitor` ~412, `filmDelete` ~420, `filmAdd` ~440, plus bulk refresh/search/file-delete/rename)

After a successful mutation, drop the instance's movie-list cache so the change is visible on the next load instead of lingering up to the TTL.

- [ ] **Step 1: Add a private invalidation helper to `MediaController`**

Add this private method to `MediaController` (e.g. just below the constructor):

```php
    /**
     * Drop the cached library list for the currently-bound instance after a
     * mutating action so the change shows on the next page load. No-op-safe
     * when no instance is bound (guards/subscribers normally prevent that).
     */
    private function invalidateRadarrLibrary(): void
    {
        $slug = $this->radarr->getInstance()?->getSlug();
        if ($slug !== null) {
            $this->libraryCache->invalidate('radarr', $slug);
        }
    }
```

- [ ] **Step 2: Call it from the list-changing movie actions**

In `filmMonitor()` (~line 412), after the write, before returning:

```php
        $monitored = (bool) ($request->toArray()['monitored'] ?? true);
        $ok        = $this->radarr->setMonitored($id, $monitored);
        if ($ok) {
            $this->invalidateRadarrLibrary();
        }
        return $this->json(['ok' => $ok, 'monitored' => $monitored]);
```

In `filmDelete()` (~line 420):

```php
        $ok          = $this->radarr->deleteMovie($id, $deleteFiles, $addExclusion);
        if ($ok) {
            $this->invalidateRadarrLibrary();
        }
        return $this->json(['ok' => $ok]);
```

In `filmAdd()` (~line 440) — after the add succeeds, before its `return`, add `$this->invalidateRadarrLibrary();`. (The add builds a payload and calls `$this->radarr->addMovie($payload)`; invalidate when the result is non-null. Read the method's existing success variable and guard on it, e.g.:)

```php
        // ... existing addMovie call producing $result ...
        if ($result !== null) {
            $this->invalidateRadarrLibrary();
        }
        // ... existing return ...
```

Also add `$this->invalidateRadarrLibrary();` after a successful write in the bulk/file actions that change the list membership or file state: `filmsBulkRefresh`, `filmsBulkSearch`, `filmFileDelete` (`films_file_delete`), and `filmRename` (`films_rename`). For each, guard on the existing success flag the action already computes (do not invalidate on a failed upstream call).

> **Note for implementer:** these write actions vary slightly in their success variable names. Read each method body first and gate the `invalidateRadarrLibrary()` call on its existing success condition. Do NOT invalidate unconditionally — a failed write must leave the cache intact.

- [ ] **Step 3: Lint + full suite**

Run: `make lint && docker exec -e APP_ENV=test prismarr vendor/bin/phpunit`
Expected: `PHP syntax OK` then PASS.

- [ ] **Step 4: Commit**

```bash
git add symfony/src/Controller/MediaController.php
git commit -m "feat(media): write-through invalidation of films cache on mutations"
```

---

## Task 7: Sonarr — extract normalizers, add `multiGet()`, rewire `series()`

**Files:**
- Modify: `symfony/src/Service/Media/SonarrClient.php` (`getSeries()` ~199, `getQueue()` ~436, `getCalendar()` ~508; add `multiGet()` after its `get()` ~1735)
- Modify: `symfony/src/Controller/MediaController.php` (`series()` ~173-253)

- [ ] **Step 1: Extract Sonarr normalizers (no behavior change)**

Replace `getSeries()` (`SonarrClient.php:199`):

```php
    public function getSeries(): array
    {
        $data = $this->get('/api/v3/series');
        if ($data === null) return [];

        return $this->normalizeSeriesList($data);
    }

    /**
     * Normalize a raw `/api/v3/series` list. Public so a concurrent multiGet
     * batch can reuse the exact transform.
     *
     * @param array<int, array<string, mixed>> $rawSeries
     * @return list<array<string, mixed>>
     */
    public function normalizeSeriesList(array $rawSeries): array
    {
        return array_map(fn($s) => $this->normalizeSeries($s), $rawSeries);
    }
```

Replace `getQueue()` (`SonarrClient.php:436`):

```php
    public function getQueue(): array
    {
        $data = $this->get('/api/v3/queue', ['pageSize' => 50, 'includeSeries' => 'true', 'includeEpisode' => 'true']);
        if ($data === null || empty($data['records'])) return [];

        return $this->normalizeQueueRecords($data['records']);
    }

    /**
     * Normalize raw queue `records` into the series-UI shape. Public so a
     * concurrent multiGet batch can reuse it.
     *
     * @param array<int, array<string, mixed>> $records
     * @return list<array<string, mixed>>
     */
    public function normalizeQueueRecords(array $records): array
    {
        return array_map(fn($r) => [
            'id'            => $r['id'] ?? null,
            'seriesId'      => $r['seriesId'] ?? null,
            'seriesTitle'   => $r['series']['title'] ?? '—',
            'episode'       => isset($r['episode'])
                ? 'S' . str_pad($r['episode']['seasonNumber'] ?? 0, 2, '0', STR_PAD_LEFT)
                  . 'E' . str_pad($r['episode']['episodeNumber'] ?? 0, 2, '0', STR_PAD_LEFT)
                  . ' — ' . ($r['episode']['title'] ?? '')
                : ($r['title'] ?? '—'),
            'size'          => $r['size'] ?? 0,
            'sizeleft'      => $r['sizeleft'] ?? 0,
            'status'        => $r['status'] ?? 'unknown',
            'trackedStatus' => $r['trackedDownloadStatus'] ?? 'ok',
            'trackedState'  => $r['trackedDownloadState'] ?? '',
            'quality'       => $r['quality']['quality']['name'] ?? '—',
            'protocol'      => $r['protocol'] ?? '—',
            'eta'           => $r['estimatedCompletionTime'] ?? null,
            'indexer'       => $r['indexer'] ?? null,
            'downloadId'    => $r['downloadId'] ?? null,
            'outputPath'    => $r['outputPath'] ?? null,
            'downloadClient' => $r['downloadClient'] ?? null,
            'statusMessages' => array_map(fn($m) => [
                'title'    => $m['title'] ?? '',
                'messages' => $m['messages'] ?? [],
            ], $r['statusMessages'] ?? []),
        ], $records);
    }
```

Replace `getCalendar()` (`SonarrClient.php:508`):

```php
    public function getCalendar(int $daysAhead = 30, int $daysBefore = 0): array
    {
        $start = (new \DateTimeImmutable("-{$daysBefore} days"))->format('Y-m-d');
        $end   = (new \DateTimeImmutable("+{$daysAhead} days"))->format('Y-m-d');
        $data  = $this->get('/api/v3/calendar', ['start' => $start, 'end' => $end, 'includeSeries' => 'true']);
        if ($data === null) return [];

        return $this->normalizeCalendarEntries($data);
    }

    /**
     * Normalize raw `/api/v3/calendar` entries. Public so a concurrent
     * multiGet batch can reuse the transform. Date handling preserved
     * exactly (see the airDate vs airDateUtc note, issue #26).
     *
     * @param array<int, array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    public function normalizeCalendarEntries(array $entries): array
    {
        return array_map(fn($e) => [
            'id'            => $e['id'] ?? null,
            'seriesId'      => $e['seriesId'] ?? null,
            'tvdbId'        => $e['series']['tvdbId'] ?? null,
            'tmdbId'        => $e['series']['tmdbId'] ?? null,
            'seriesTitle'   => $e['series']['title'] ?? '—',
            'poster'        => $this->posterUrl($e['series'] ?? []),
            'fanart'        => $this->fanartUrl($e['series'] ?? []),
            'season'        => $e['seasonNumber'] ?? 0,
            'episode'       => $e['episodeNumber'] ?? 0,
            'title'         => $e['title'] ?? '—',
            'overview'      => $e['overview'] ?? ($e['series']['overview'] ?? null),
            'airDate'       => isset($e['airDate'])
                ? new \DateTimeImmutable($e['airDate'], new \DateTimeZone('UTC'))
                : (isset($e['airDateUtc']) ? new \DateTimeImmutable($e['airDateUtc']) : null),
            'hasFile'       => (bool) ($e['hasFile'] ?? false),
            'monitored'     => (bool) ($e['monitored'] ?? false),
            'runtime'       => $e['runtime'] ?? ($e['series']['runtime'] ?? null),
            'network'       => $e['series']['network'] ?? null,
            'genres'        => $e['series']['genres'] ?? [],
        ], $entries);
    }
```

- [ ] **Step 2: Add `multiGet()` to `SonarrClient`**

Insert immediately after `SonarrClient::get()` (after line ~1735). Note `self::SERVICE_KEY` is `'sonarr'` in this class and the warning prefix says `SonarrClient`:

```php
    /**
     * Concurrent GET of several endpoints in one curl_multi batch. Same
     * per-handle semantics as get(); see RadarrClient::multiGet() for the
     * full rationale (collapses N sequential calls and N × timeout-stacking
     * into a single batch).
     *
     * @param array<string, array{path: string, params?: array}> $requests
     * @return array<string, ?array>
     */
    public function multiGet(array $requests): array
    {
        $out = array_fill_keys(array_keys($requests), null);
        if ($requests === []) {
            return $out;
        }
        if ($this->health->isDown(self::SERVICE_KEY, $this->instance?->getSlug()) || $this->serviceUnavailable) {
            $this->serviceUnavailable = true;
            return $out;
        }
        $this->lastError = null;
        $this->ensureConfig();

        $mh      = curl_multi_init();
        $handles = [];
        foreach ($requests as $name => $spec) {
            $url = rtrim($this->baseUrl, '/') . $spec['path'];
            if (!empty($spec['params'])) {
                $url .= '?' . http_build_query($spec['params']);
            }
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS, // SSRF guard
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_NOSIGNAL       => 1,
                CURLOPT_HTTPHEADER     => ["X-Api-Key: {$this->apiKey}", 'Accept: application/json'],
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$name] = $ch;
        }

        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $networkError = false;
        foreach ($handles as $name => $ch) {
            $body = curl_multi_getcontent($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            if ($err !== '' || (int) $code === 0) {
                $networkError = true;
                $this->logger->warning("SonarrClient multiGet {$name} → HTTP {$code} {$err}");
                continue;
            }
            if ((int) $code !== 200) {
                $this->logger->warning("SonarrClient multiGet {$name} → HTTP {$code}");
                continue;
            }
            $decoded = json_decode((string) $body, true);
            $out[$name] = is_array($decoded) ? $decoded : null;
        }
        curl_multi_close($mh);

        if ($networkError) {
            $this->serviceUnavailable = true;
            $this->health->markDown(self::SERVICE_KEY, $this->instance?->getSlug());
        } else {
            $this->health->clear(self::SERVICE_KEY, $this->instance?->getSlug());
        }

        return $out;
    }
```

- [ ] **Step 3: Rewire `MediaController::series()`**

Replace the data-fetch block of `series()` — from `set_time_limit(120);` (~line 180) through the end of the `} catch (\Throwable $e) { ... $error = true; }` block (~line 198) — with:

```php
        set_time_limit(120);

        $series   = [];
        $queue    = [];
        $calendar = [];
        $error    = false;

        $instance = $this->sonarr->getInstance() ?? $this->instances->getDefault(ServiceInstance::TYPE_SONARR);
        if ($instance === null) {
            throw $this->createNotFoundException('No Sonarr instance configured.');
        }
        $slug = $instance->getSlug();

        try {
            $batch = $this->sonarr->multiGet([
                'status'   => ['path' => '/api/v3/system/status'],
                'queue'    => ['path' => '/api/v3/queue', 'params' => ['pageSize' => 50, 'includeSeries' => 'true', 'includeEpisode' => 'true']],
                'calendar' => ['path' => '/api/v3/calendar', 'params' => ['start' => (new \DateTimeImmutable('now'))->format('Y-m-d'), 'end' => (new \DateTimeImmutable('+14 days'))->format('Y-m-d'), 'includeSeries' => 'true']],
            ]);

            if ($batch['status'] === null) {
                $error = true;
            } else {
                $series   = $this->libraryCache->series($slug, fn() => $this->sonarr->getSeries());
                $queue    = $this->sonarr->normalizeQueueRecords($batch['queue']['records'] ?? []);
                $calendar = $this->sonarr->normalizeCalendarEntries($batch['calendar'] ?? []);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Media series failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }
```

Then replace the later `$current` resolution in `series()` (~line 237):

```php
        $current = $this->sonarr->getInstance() ?? $this->instances->getDefault(ServiceInstance::TYPE_SONARR);
        if ($current === null) {
            throw $this->createNotFoundException('No Sonarr instance configured.');
        }
```

with:

```php
        $current = $instance;
```

Leave the `?open=` deep-link block, `$filter->apply(...)`, stats, and the `return $this->render('media/series.html.twig', [...])` unchanged.

> **Note for implementer:** the original `series()` used `getCalendar(14)` (14 days ahead, 0 before). The multiGet `calendar` params above reproduce that window (`now` → `+14 days`). Keep them in sync.

- [ ] **Step 4: Lint + full suite + series smoke test**

Run: `make lint && docker exec -e APP_ENV=test prismarr vendor/bin/phpunit`
Expected: `PHP syntax OK` then PASS — including `testSeriesPageRendersWhenSonarrUnreachable` from Task 5.

- [ ] **Step 5: Commit**

```bash
git add symfony/src/Service/Media/SonarrClient.php symfony/src/Controller/MediaController.php
git commit -m "perf(media): series() uses cached library + concurrent batch fetch"
```

---

## Task 8: Write-through invalidation for the series write actions

**Files:**
- Modify: `symfony/src/Controller/MediaController.php` (the series/episode write actions)

- [ ] **Step 1: Add a Sonarr invalidation helper**

Add below `invalidateRadarrLibrary()`:

```php
    /** Drop the cached series list for the bound Sonarr instance. */
    private function invalidateSonarrLibrary(): void
    {
        $slug = $this->sonarr->getInstance()?->getSlug();
        if ($slug !== null) {
            $this->libraryCache->invalidate('sonarr', $slug);
        }
    }
```

- [ ] **Step 2: Call it from the list-changing series actions**

Locate the series write actions in `MediaController` (the `series_*` / episode routes — add series, delete series, monitor series/season toggles, and any bulk series action). For each, after the write and gated on its existing success flag, call `$this->invalidateSonarrLibrary();`.

> **Note for implementer:** grep within `MediaController.php` for the series mutation routes (e.g. `name: 'series_add'`, `series_delete`, `series_monitor`, season-monitor toggles, series bulk actions). Mirror the films pattern: invalidate only on a successful upstream write, never unconditionally. Read each method body to find its success variable before adding the call.

- [ ] **Step 3: Lint + full suite**

Run: `make lint && docker exec -e APP_ENV=test prismarr vendor/bin/phpunit`
Expected: `PHP syntax OK` then PASS.

- [ ] **Step 4: Commit**

```bash
git add symfony/src/Controller/MediaController.php
git commit -m "feat(media): write-through invalidation of series cache on mutations"
```

---

## Task 9: Final verification + changelog

**Files:**
- Modify: `CHANGELOG.md` (top of the current unreleased section)

- [ ] **Step 1: Add a changelog entry**

Add under the unreleased/in-progress heading in `CHANGELOG.md` (match the existing bullet style):

```markdown
- **perf:** Radarr/Sonarr library pages now cache the movie/series list (45 s, per-instance) and fetch the remaining status/queue/indexer/health/calendar calls concurrently instead of sequentially — large libraries and briefly-unreachable services no longer stall the page for 10–15 s. Library mutations invalidate the cache so changes show immediately.
```

- [ ] **Step 2: Full pre-commit gate**

Run: `make check`
Expected: `✓ make check passed — ready to commit` (lint + lint-twig + full PHPUnit suite all green).

- [ ] **Step 3: Commit**

```bash
git add CHANGELOG.md
git commit -m "docs(changelog): note library page caching + concurrent fetch"
```

---

## Self-Review

**Spec coverage:**
- Cache library list (heavy, slow-changing) → Task 1 (`MediaLibraryCache`) + Tasks 4/7 (wired into films/series). ✓
- `multiGet` concurrent fetch of cheap volatile calls → Tasks 3/7. ✓
- Reuse normalizers, don't duplicate → Tasks 2/7 (public `normalizeMovies`/`normalizeQueueRecords`/`normalizeSeriesList`/`normalizeCalendarEntries`). ✓
- Write-through invalidation on mutations → Tasks 6/8. ✓
- Error handling unchanged (circuit breaker, `$error` on null status) → preserved in `multiGet` (Tasks 3/7) and the controller `$batch['status'] === null` branch. ✓
- Per-instance cache keys, empty-not-cached, 45 s TTL → Task 1. ✓
- Tests: `MediaLibraryCache` unit, `multiGet` short-circuit unit, controller smoke → Tasks 1/3/5. ✓

**Deviation noted:** cold-path single-batch-including-list simplified to cache-callback fetch (flagged at top for reviewer).

**Placeholder scan:** The two "Note for implementer" blocks (Tasks 6/8 bulk/series actions) intentionally defer to reading each method's existing success variable rather than inventing names for methods not fully quoted here — they specify the exact rule (gate on existing success flag, never invalidate unconditionally) and which routes to touch. All code-bearing steps contain complete code.

**Type consistency:** `multiGet(array $requests): array<string,?array>` consumed as `$batch['status']`/`$batch['queue']['records']`/`$batch['indexers']`/`$batch['health']`/`$batch['calendar']` — keys match the request maps. `MediaLibraryCache::movies/series(string,callable):array` and `invalidate(string $type,string $slug)` with `'radarr'`/`'sonarr'` — consistent across Tasks 1/4/6/7/8. Public normalizers named identically where defined and called.
```
