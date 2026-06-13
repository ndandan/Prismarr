# Tautulli Activity Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Tautulli sidebar nav entry that opens a dedicated, full "Tautulli — Plex Activity" page (live streams, watch stats, plays-over-time graph, history, libraries) where clicking any title opens the existing info modal.

**Architecture:** Server-rendered page + async fragment hydration, matching the existing dashboard/service-page conventions. New read-only `TautulliClient` methods return allow-listed, sanitized shapes via pure static normalizers (unit-tested). New `TautulliController` endpoints render HTML fragments (except the plays graph, which returns JSON for Chart.js). Existing widget markup (stream card, history row, info modal, styles) is extracted into shared partials reused by both the dashboard widget and the new page. Same security model as the rest of the integration: the API key stays server-side; every endpoint fails open.

**Tech Stack:** Symfony 7 (PHP 8.4), Twig, FrankenPHP, PHPUnit, Tabler/Bootstrap, Chart.js 4 (CDN), vanilla JS.

**Note on running tests:** there is no local PHP toolchain. Run PHPUnit inside the container: `docker exec -e APP_ENV=test prismarr vendor/bin/phpunit --filter <name>`, or rely on CI (`make check`) after each commit. Twig/YAML lints: `docker exec prismarr php bin/console lint:twig templates` / `lint:yaml translations`.

**Key existing facts (verified):**
- `TautulliClient::request(array $params = ['cmd' => 'get_activity']): ?array` returns `['ok' => bool, 'data' => mixed, ...]` or `null`. Helpers `self::str()`, `self::strList()`, `self::pickPoster()` and `self::normalizeHistory()` already exist.
- `getActivity()` returns the sanitized envelope `{enabled, configured, connected, error, streamCount, …, sessions:[…]}`; each session has `ratingKey, mediaType, posterPath, title, year, player, device, userDisplayName, product, state, transcodeDecision, location, quality, bandwidthMbps, videoDecision, audioDecision, subtitleDecision, progressPercent`.
- Tautulli is already in `HealthService::TOGGLEABLE_SERVICES`; config keys are `tautulli_url` + `tautulli_api_key`. Settings page route is `admin_settings_index`.
- Chart.js is loaded per-page via `<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>` then `new Chart(ctx, …)` (see `radarr/stats.html.twig`).
- The dashboard `.plex-*` CSS lives in `dashboard/index.html.twig` lines 459–479. The info-modal shell + hidden trigger live there too; the modal open/keydown handler lives in the dashboard JS IIFE.

---

### Task 1: `TautulliClient::getHomeStats` + `normalizeHomeStats` + `clampRange`

**Files:**
- Modify: `symfony/src/Service/Media/TautulliClient.php`
- Test: `symfony/tests/Service/Media/TautulliClientTest.php`

- [ ] **Step 1: Write the failing tests**

Add to `TautulliClientTest.php` before the final closing `}`:

```php
    /** A representative get_home_stats `data` list (groups with rows). */
    private function homeStatsFixture(): array
    {
        return [
            ['stat_id' => 'top_movies', 'rows' => [
                ['rating_key' => '12345', 'title' => 'See How They Run', 'year' => '2022',
                 'total_plays' => 7, 'thumb' => '/library/metadata/12345/thumb/1',
                 'file' => '/data/media/x.mkv', 'guid' => 'plex://movie/abc'],
            ]],
            ['stat_id' => 'top_tv', 'rows' => [
                ['rating_key' => '777', 'title' => "Tom Clancy's Jack Ryan", 'total_plays' => 12,
                 'thumb' => '/library/metadata/700/thumb/9', 'grandparent_thumb' => '/library/metadata/100/thumb/2'],
            ]],
            ['stat_id' => 'top_users', 'rows' => [
                ['user' => 'plexlogin_secret', 'friendly_name' => 'nDanDan', 'user_id' => 99,
                 'total_plays' => 30, 'user_thumb' => 'https://plex.tv/users/abc/avatar'],
            ]],
            ['stat_id' => 'top_platforms', 'rows' => [
                ['platform' => 'Chrome', 'platform_name' => 'Chrome', 'total_plays' => 18],
            ]],
            ['stat_id' => 'last_watched', 'rows' => [['title' => 'ignored']]],
        ];
    }

    public function testNormalizeHomeStatsMapsGroups(): void
    {
        $out = TautulliClient::normalizeHomeStats($this->homeStatsFixture());

        self::assertSame('12345', $out['topMovies'][0]['ratingKey']);
        self::assertSame('See How They Run', $out['topMovies'][0]['title']);
        self::assertSame('2022', $out['topMovies'][0]['year']);
        self::assertSame('/library/metadata/12345/thumb/1', $out['topMovies'][0]['posterPath']);
        self::assertSame(7, $out['topMovies'][0]['plays']);

        // Episodes/shows prefer the grandparent (show) poster.
        self::assertSame('/library/metadata/100/thumb/2', $out['topShows'][0]['posterPath']);
        self::assertSame(12, $out['topShows'][0]['plays']);

        self::assertSame('nDanDan', $out['topUsers'][0]['userDisplayName']);
        self::assertSame(30, $out['topUsers'][0]['plays']);

        self::assertSame('Chrome', $out['topPlatforms'][0]['platform']);
        self::assertSame(18, $out['topPlatforms'][0]['plays']);
    }

    public function testNormalizeHomeStatsNeverLeaksPrivateFields(): void
    {
        $flat = json_encode(TautulliClient::normalizeHomeStats($this->homeStatsFixture()));
        self::assertStringNotContainsString('plexlogin_secret', $flat);
        self::assertStringNotContainsString('/data/media', $flat);
        self::assertStringNotContainsString('plex://', $flat);
        self::assertStringNotContainsString('avatar', $flat);
        foreach (TautulliClient::normalizeHomeStats($this->homeStatsFixture())['topUsers'] as $u) {
            self::assertArrayNotHasKey('user', $u);
            self::assertArrayNotHasKey('user_id', $u);
        }
    }

    public function testNormalizeHomeStatsEmptyInputYieldsEmptyLists(): void
    {
        $out = TautulliClient::normalizeHomeStats([]);
        self::assertSame(['topMovies' => [], 'topShows' => [], 'topUsers' => [], 'topPlatforms' => []], $out);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker exec -e APP_ENV=test prismarr vendor/bin/phpunit --filter normalizeHomeStats`
Expected: FAIL — `Call to undefined method App\Service\Media\TautulliClient::normalizeHomeStats()`.

- [ ] **Step 3: Add `clampRange`, `getHomeStats`, `normalizeHomeStats`**

In `TautulliClient.php`, add after `getHistory()` (before `normalizeHistory()`):

```php
    /** Clamp a stats window to the allowed presets; anything else → 30 days. */
    private static function clampRange(int $days): int
    {
        return in_array($days, [7, 30, 90], true) ? $days : 30;
    }

    /**
     * Watch statistics for the home page, normalized + sanitized. Returns the
     * four lists the activity page renders; an all-empty shape covers
     * disabled/unconfigured/unreachable.
     *
     * @return array{topMovies:list<array<string,mixed>>, topShows:list<array<string,mixed>>, topUsers:list<array<string,mixed>>, topPlatforms:list<array<string,mixed>>}
     */
    public function getHomeStats(int $days): array
    {
        $this->ensureConfig();
        if (!$this->enabled || $this->baseUrl === '' || $this->apiKey === '') {
            return self::normalizeHomeStats([]);
        }
        $resp = $this->request([
            'cmd'         => 'get_home_stats',
            'time_range'  => (string) self::clampRange($days),
            'stats_count' => '5',
        ]);
        if ($resp === null || $resp['ok'] !== true) {
            return self::normalizeHomeStats([]);
        }
        return self::normalizeHomeStats(is_array($resp['data']) ? $resp['data'] : []);
    }

    /**
     * Pure transform: get_home_stats `data` (list of stat groups) → sanitized
     * lists. Allow-list only: usernames/emails/ids/avatars/file paths/guids are
     * never copied out. Only the four groups we render are kept.
     *
     * @param array<int, mixed> $data
     * @return array{topMovies:list<array<string,mixed>>, topShows:list<array<string,mixed>>, topUsers:list<array<string,mixed>>, topPlatforms:list<array<string,mixed>>}
     */
    public static function normalizeHomeStats(array $data): array
    {
        $out = ['topMovies' => [], 'topShows' => [], 'topUsers' => [], 'topPlatforms' => []];
        foreach ($data as $group) {
            if (!is_array($group)) {
                continue;
            }
            $rows = is_array($group['rows'] ?? null) ? $group['rows'] : [];
            switch (self::str($group['stat_id'] ?? null)) {
                case 'top_movies':
                    foreach ($rows as $r) {
                        if (!is_array($r)) { continue; }
                        $out['topMovies'][] = [
                            'ratingKey'  => self::str($r['rating_key'] ?? null),
                            'title'      => self::str($r['title'] ?? null),
                            'year'       => self::str($r['year'] ?? null),
                            'posterPath' => self::str($r['thumb'] ?? ($r['grandparent_thumb'] ?? null)),
                            'plays'      => (int) ($r['total_plays'] ?? 0),
                        ];
                    }
                    break;
                case 'top_tv':
                    foreach ($rows as $r) {
                        if (!is_array($r)) { continue; }
                        $out['topShows'][] = [
                            'ratingKey'  => self::str($r['rating_key'] ?? null),
                            'title'      => self::str($r['title'] ?? null),
                            'posterPath' => self::str($r['grandparent_thumb'] ?? ($r['thumb'] ?? null)),
                            'plays'      => (int) ($r['total_plays'] ?? 0),
                        ];
                    }
                    break;
                case 'top_users':
                    foreach ($rows as $r) {
                        if (!is_array($r)) { continue; }
                        $out['topUsers'][] = [
                            'userDisplayName' => self::str($r['friendly_name'] ?? null),
                            'plays'           => (int) ($r['total_plays'] ?? 0),
                        ];
                    }
                    break;
                case 'top_platforms':
                    foreach ($rows as $r) {
                        if (!is_array($r)) { continue; }
                        $out['topPlatforms'][] = [
                            'platform' => self::str($r['platform_name'] ?? ($r['platform'] ?? null)),
                            'plays'    => (int) ($r['total_plays'] ?? 0),
                        ];
                    }
                    break;
            }
        }
        return $out;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker exec -e APP_ENV=test prismarr vendor/bin/phpunit --filter "normalizeHomeStats|TautulliClient"`
Expected: PASS (existing + new tests green).

- [ ] **Step 5: Commit**

```bash
git add symfony/src/Service/Media/TautulliClient.php symfony/tests/Service/Media/TautulliClientTest.php
git commit -m "feat(tautulli): add getHomeStats + normalizeHomeStats + range clamp"
```

---

### Task 2: `TautulliClient::getPlaysByDate` + `normalizePlaysByDate`

**Files:**
- Modify: `symfony/src/Service/Media/TautulliClient.php`
- Test: `symfony/tests/Service/Media/TautulliClientTest.php`

- [ ] **Step 1: Write the failing tests**

```php
    /** A representative get_plays_by_date `data` envelope. */
    private function playsByDateFixture(): array
    {
        return [
            'categories' => ['2026-06-01', '2026-06-02', '2026-06-03'],
            'series'     => [
                ['name' => 'TV',     'data' => [3, 0, 5]],
                ['name' => 'Movies', 'data' => [1, 2, 0]],
            ],
        ];
    }

    public function testNormalizePlaysByDateMapsSeries(): void
    {
        $out = TautulliClient::normalizePlaysByDate($this->playsByDateFixture());
        self::assertSame(['2026-06-01', '2026-06-02', '2026-06-03'], $out['categories']);
        self::assertCount(2, $out['series']);
        self::assertSame('TV', $out['series'][0]['name']);
        self::assertSame([3, 0, 5], $out['series'][0]['data']);
        self::assertSame([1, 2, 0], $out['series'][1]['data']);
    }

    public function testNormalizePlaysByDateCoercesAndDefaults(): void
    {
        $out = TautulliClient::normalizePlaysByDate(['series' => [['data' => ['2', '4']]]]);
        self::assertSame([], $out['categories']);
        self::assertSame('', $out['series'][0]['name']);
        self::assertSame([2, 4], $out['series'][0]['data']); // string → int
    }

    public function testNormalizePlaysByDateEmpty(): void
    {
        self::assertSame(['categories' => [], 'series' => []], TautulliClient::normalizePlaysByDate([]));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker exec -e APP_ENV=test prismarr vendor/bin/phpunit --filter normalizePlaysByDate`
Expected: FAIL — undefined method `normalizePlaysByDate`.

- [ ] **Step 3: Add `getPlaysByDate` + `normalizePlaysByDate`**

Add after `normalizeHomeStats()`:

```php
    /**
     * Plays-per-day series for the activity graph, ready for Chart.js.
     *
     * @return array{categories:list<string>, series:list<array{name:string,data:list<int>}>}
     */
    public function getPlaysByDate(int $days): array
    {
        $this->ensureConfig();
        if (!$this->enabled || $this->baseUrl === '' || $this->apiKey === '') {
            return ['categories' => [], 'series' => []];
        }
        $resp = $this->request([
            'cmd'        => 'get_plays_by_date',
            'time_range' => (string) self::clampRange($days),
        ]);
        if ($resp === null || $resp['ok'] !== true) {
            return ['categories' => [], 'series' => []];
        }
        return self::normalizePlaysByDate(is_array($resp['data']) ? $resp['data'] : []);
    }

    /**
     * Pure transform: get_plays_by_date `data` → {categories, series}. Series
     * data is coerced to ints; only name + data survive.
     *
     * @param array<string, mixed> $data
     * @return array{categories:list<string>, series:list<array{name:string,data:list<int>}>}
     */
    public static function normalizePlaysByDate(array $data): array
    {
        $series = [];
        $rawSeries = is_array($data['series'] ?? null) ? $data['series'] : [];
        foreach ($rawSeries as $s) {
            if (!is_array($s)) {
                continue;
            }
            $vals = is_array($s['data'] ?? null) ? $s['data'] : [];
            $series[] = [
                'name' => self::str($s['name'] ?? null) ?? '',
                'data' => array_map(static fn ($v) => (int) $v, array_values($vals)),
            ];
        }
        return ['categories' => self::strList($data['categories'] ?? []), 'series' => $series];
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker exec -e APP_ENV=test prismarr vendor/bin/phpunit --filter "normalizePlaysByDate|TautulliClient"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add symfony/src/Service/Media/TautulliClient.php symfony/tests/Service/Media/TautulliClientTest.php
git commit -m "feat(tautulli): add getPlaysByDate + normalizePlaysByDate"
```

---

### Task 3: `TautulliClient::getLibraries` + `normalizeLibraries`

**Files:**
- Modify: `symfony/src/Service/Media/TautulliClient.php`
- Test: `symfony/tests/Service/Media/TautulliClientTest.php`

- [ ] **Step 1: Write the failing tests**

```php
    /** A representative get_libraries `data` list. */
    private function librariesFixture(): array
    {
        return [
            ['section_id' => '1', 'section_name' => 'Movies', 'section_type' => 'movie',
             'count' => '1204', 'thumb' => '/library/sections/1/thumb'],
            ['section_id' => '2', 'section_name' => 'TV Shows', 'section_type' => 'show',
             'count' => '312', 'parent_count' => '900', 'child_count' => '8800'],
        ];
    }

    public function testNormalizeLibrariesMapsRows(): void
    {
        $out = TautulliClient::normalizeLibraries($this->librariesFixture());
        self::assertCount(2, $out);
        self::assertSame('Movies', $out[0]['name']);
        self::assertSame('movie', $out[0]['type']);
        self::assertSame(1204, $out[0]['count']);
        self::assertNull($out[0]['childCount']);
        self::assertSame('show', $out[1]['type']);
        self::assertSame(312, $out[1]['count']);
        self::assertSame(8800, $out[1]['childCount']);
    }

    public function testNormalizeLibrariesDropsPrivateFields(): void
    {
        $flat = json_encode(TautulliClient::normalizeLibraries($this->librariesFixture()));
        self::assertStringNotContainsString('section_id', $flat);
        self::assertStringNotContainsString('/library/sections', $flat);
        foreach (TautulliClient::normalizeLibraries($this->librariesFixture()) as $lib) {
            self::assertArrayNotHasKey('section_id', $lib);
            self::assertArrayNotHasKey('thumb', $lib);
        }
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker exec -e APP_ENV=test prismarr vendor/bin/phpunit --filter normalizeLibraries`
Expected: FAIL — undefined method `normalizeLibraries`.

- [ ] **Step 3: Add `getLibraries` + `normalizeLibraries`**

Add after `normalizePlaysByDate()`:

```php
    /**
     * Library sections with item counts, normalized + sanitized.
     *
     * @return list<array{name:?string,type:?string,count:int,childCount:?int}>
     */
    public function getLibraries(): array
    {
        $this->ensureConfig();
        if (!$this->enabled || $this->baseUrl === '' || $this->apiKey === '') {
            return [];
        }
        $resp = $this->request(['cmd' => 'get_libraries']);
        if ($resp === null || $resp['ok'] !== true) {
            return [];
        }
        return self::normalizeLibraries(is_array($resp['data']) ? $resp['data'] : []);
    }

    /**
     * Pure transform: get_libraries `data` → sanitized rows. Names + counts
     * only; section ids, thumbs and paths are dropped.
     *
     * @param array<int, mixed> $data
     * @return list<array{name:?string,type:?string,count:int,childCount:?int}>
     */
    public static function normalizeLibraries(array $data): array
    {
        $out = [];
        foreach ($data as $lib) {
            if (!is_array($lib)) {
                continue;
            }
            $out[] = [
                'name'       => self::str($lib['section_name'] ?? null),
                'type'       => self::str($lib['section_type'] ?? null),
                'count'      => (int) ($lib['count'] ?? 0),
                'childCount' => isset($lib['child_count']) ? (int) $lib['child_count'] : null,
            ];
        }
        return $out;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker exec -e APP_ENV=test prismarr vendor/bin/phpunit --filter "normalizeLibraries|TautulliClient"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add symfony/src/Service/Media/TautulliClient.php symfony/tests/Service/Media/TautulliClientTest.php
git commit -m "feat(tautulli): add getLibraries + normalizeLibraries"
```

---

### Task 4: paginate `getHistory` with a `start` offset

**Files:**
- Modify: `symfony/src/Service/Media/TautulliClient.php`
- Test: `symfony/tests/Service/Media/TautulliClientTest.php`

- [ ] **Step 1: Write the failing test**

The current `getHistory(int $length = 8)` has no offset. Add a test asserting the new `$start` param is forwarded. Because `request()` is private, the test verifies behavior through the public signature by calling with two args (a fatal `ArgumentCountError`/too-many-args would fail compilation today since the 2nd param doesn't exist):

```php
    public function testGetHistoryAcceptsStartOffset(): void
    {
        // New signature accepts (length, start). On an unconfigured client it
        // returns [] without error — proves the 2-arg signature exists.
        $client = new TautulliClient(
            new \App\Service\ConfigService(self::staticConfigRepoStub()),
            new \Psr\Log\NullLogger(),
            null,
        );
        self::assertSame([], $client->getHistory(25, 25));
    }
```

> Note: if the existing `TautulliClientTest` already constructs a `TautulliClient` via a helper (check the top of the file), reuse that helper instead of the inline constructor above — match the file's existing construction pattern. The assertion (empty array on an unconfigured client + a 2-arg call) is what matters.

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec -e APP_ENV=test prismarr vendor/bin/phpunit --filter testGetHistoryAcceptsStartOffset`
Expected: FAIL — too many arguments to `getHistory()` (only 1 param defined).

- [ ] **Step 3: Add the `$start` parameter**

In `getHistory()`, change the signature and add the `start` query param:

```php
    public function getHistory(int $length = 8, int $start = 0): array
    {
        $this->ensureConfig();
        if (!$this->enabled || $this->baseUrl === '' || $this->apiKey === '') {
            return [];
        }
        $resp = $this->request([
            'cmd'          => 'get_history',
            'length'       => (string) max(1, min(50, $length)),
            'start'        => (string) max(0, $start),
            'order_column' => 'date',
            'order_dir'    => 'desc',
        ]);
        if ($resp === null || $resp['ok'] !== true) {
            return [];
        }
        return self::normalizeHistory($resp['data']);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker exec -e APP_ENV=test prismarr vendor/bin/phpunit --filter "TautulliClient"`
Expected: PASS (the existing `getHistory(8)` callsite in `DashboardController` still works via the default `$start = 0`).

- [ ] **Step 5: Commit**

```bash
git add symfony/src/Service/Media/TautulliClient.php symfony/tests/Service/Media/TautulliClientTest.php
git commit -m "feat(tautulli): add start offset to getHistory for pagination"
```

---

### Task 5: shared `relative_date` Twig filter

**Files:**
- Create: `symfony/src/Twig/RelativeDateExtension.php`
- Test: `symfony/tests/Twig/RelativeDateExtensionTest.php`

Both the dashboard widget and the new page render history rows with a friendly "watched X ago" label. Today that logic is a private method in `DashboardController`. Extract a pure bucket function + a Twig filter so the shared history-row partial (Task 6) can format `watchedAt` directly.

- [ ] **Step 1: Write the failing test (pure bucket logic)**

Create `symfony/tests/Twig/RelativeDateExtensionTest.php`:

```php
<?php

namespace App\Tests\Twig;

use App\Twig\RelativeDateExtension;
use PHPUnit\Framework\TestCase;

final class RelativeDateExtensionTest extends TestCase
{
    public function testBucketBoundaries(): void
    {
        self::assertSame(['dashboard.relative.today', []],            RelativeDateExtension::bucket(0));
        self::assertSame(['dashboard.relative.yesterday', []],        RelativeDateExtension::bucket(1));
        self::assertSame(['dashboard.relative.days_ago', ['count' => 5]],   RelativeDateExtension::bucket(5));
        self::assertSame(['dashboard.relative.weeks_ago', ['count' => 2]],  RelativeDateExtension::bucket(14));
        self::assertSame(['dashboard.relative.months_ago', ['count' => 2]], RelativeDateExtension::bucket(60));
        self::assertSame(['dashboard.relative.years_ago', ['count' => 1]],  RelativeDateExtension::bucket(400));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec -e APP_ENV=test prismarr vendor/bin/phpunit --filter RelativeDateExtension`
Expected: FAIL — class `App\Twig\RelativeDateExtension` not found.

- [ ] **Step 3: Create the extension**

Create `symfony/src/Twig/RelativeDateExtension.php`:

```php
<?php

namespace App\Twig;

use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `relative_date(epochSeconds)` → a translated "today / yesterday / 5 days ago"
 * label. The same buckets as DashboardController used; extracted so the shared
 * Plex history-row partial can format watch timestamps directly.
 */
final class RelativeDateExtension extends AbstractExtension
{
    public function __construct(private readonly TranslatorInterface $translator) {}

    public function getFilters(): array
    {
        return [new TwigFilter('relative_date', [$this, 'format'])];
    }

    public function format(int|string|null $epoch): ?string
    {
        $epoch = (int) $epoch;
        if ($epoch <= 0) {
            return null;
        }
        $at  = (new \DateTimeImmutable())->setTimestamp($epoch);
        $now = new \DateTimeImmutable();
        [$key, $params] = self::bucket((int) $now->diff($at)->days);
        return $this->translator->trans($key, $params);
    }

    /**
     * Pure: day-difference → [translation key, params]. Mirrors the buckets in
     * DashboardController::relativeDate.
     *
     * @return array{0:string,1:array<string,int>}
     */
    public static function bucket(int $days): array
    {
        if ($days <= 0)  { return ['dashboard.relative.today', []]; }
        if ($days === 1) { return ['dashboard.relative.yesterday', []]; }
        if ($days < 7)   { return ['dashboard.relative.days_ago',   ['count' => $days]]; }
        if ($days < 30)  { return ['dashboard.relative.weeks_ago',  ['count' => (int) round($days / 7)]]; }
        if ($days < 365) { return ['dashboard.relative.months_ago', ['count' => (int) round($days / 30)]]; }
        return ['dashboard.relative.years_ago', ['count' => (int) round($days / 365)]];
    }
}
```

(Symfony autoconfigures Twig extensions via `services.yaml` autowiring — no manual registration needed.)

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec -e APP_ENV=test prismarr vendor/bin/phpunit --filter RelativeDateExtension`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add symfony/src/Twig/RelativeDateExtension.php symfony/tests/Twig/RelativeDateExtensionTest.php
git commit -m "feat(twig): add relative_date filter (shared watch-time label)"
```

---

### Task 6: extract shared Plex partials + rewire the dashboard widget

**Files:**
- Create: `symfony/templates/dashboard/_plex_session_card.html.twig`
- Create: `symfony/templates/dashboard/_plex_history_row.html.twig`
- Create: `symfony/templates/dashboard/_plex_info_modal.html.twig`
- Create: `symfony/templates/dashboard/_plex_styles.html.twig`
- Modify: `symfony/templates/dashboard/_plex_activity.html.twig`
- Modify: `symfony/templates/dashboard/index.html.twig`
- Modify: `symfony/src/Controller/DashboardController.php:277-283`

Goal: no visible change to the dashboard. Extract reusable pieces so Task 8's page can include them.

- [ ] **Step 1: Create `_plex_session_card.html.twig`** (the live-stream card; self-contained, takes `s`)

```twig
{# One live Plex session card. Expects `s` (a normalized session). Carries the
   info-modal triggers when a ratingKey is present. #}
{% import '_icons.html.twig' as ico %}
{% set state = (s.state|default(''))|lower %}
{% set state_known = state in ['playing', 'paused', 'buffering'] %}
{% set state_cls = {playing: 'bg-green-lt text-green', paused: 'bg-yellow-lt text-yellow', buffering: 'bg-azure-lt text-azure'} %}
{% set dec = (s.transcodeDecision|default(''))|lower %}
{% set dec_label = {'direct play': 'dashboard.plex.decision.direct_play', 'copy': 'dashboard.plex.decision.direct_stream', 'transcode': 'dashboard.plex.decision.transcode'} %}
{% set dec_cls = {'direct play': 'bg-green-lt text-green', 'copy': 'bg-azure-lt text-azure', 'transcode': 'bg-orange-lt text-orange'} %}
<div class="plex-session">
  <div class="plex-poster {% if s.ratingKey %}plex-clickable{% endif %}"
       {% if s.ratingKey %}data-plex-rating-key="{{ s.ratingKey }}" data-plex-player="{{ s.player }}" data-plex-device="{{ s.device }}" role="button" tabindex="0"{% endif %}>
    {{ ico.icon(s.mediaType == 'movie' ? 'movie' : 'device-tv', '', 22) }}
    {% if s.posterPath %}
      <img class="plex-poster-img" src="{{ path('app_tautulli_api_image', {img: s.posterPath}) }}" alt="" loading="lazy">
    {% endif %}
  </div>
  <div class="plex-session-main">
    <div class="plex-session-title text-truncate {% if s.ratingKey %}plex-clickable{% endif %}"
         {% if s.ratingKey %}data-plex-rating-key="{{ s.ratingKey }}" data-plex-player="{{ s.player }}" data-plex-device="{{ s.device }}" role="button" tabindex="0"{% endif %}>
      {{ s.title ?? 'dashboard.plex.unknown_title'|trans }}{% if s.year %} <span class="plex-session-year">({{ s.year }})</span>{% endif %}
    </div>
    <div class="plex-session-meta text-truncate">
      {%- set meta = [s.userDisplayName, s.product, s.player, s.device]|filter(v => v is not null) -%}
      {{ meta|join(' · ') }}
    </div>
    <div class="plex-badges">
      {% if state %}<span class="badge {{ state_cls[state]|default('bg-secondary-lt') }}">{{ state_known ? ('dashboard.plex.state.' ~ state)|trans : state|capitalize }}</span>{% endif %}
      {% if dec %}<span class="badge {{ dec_cls[dec]|default('bg-secondary-lt') }}">{{ dec_label[dec] is defined ? dec_label[dec]|trans : dec|capitalize }}</span>{% endif %}
      {% if s.location %}<span class="badge bg-secondary-lt text-uppercase">{{ s.location }}</span>{% endif %}
      {% if s.quality %}<span class="badge bg-secondary-lt">{{ s.quality }}</span>{% endif %}
      {% if s.bandwidthMbps > 0 %}<span class="badge bg-secondary-lt">{{ s.bandwidthMbps }} {{ 'dashboard.plex.mbps'|trans }}</span>{% endif %}
    </div>
    {% if dec == 'transcode' %}
      <div class="plex-decisions text-secondary">
        {{ 'dashboard.plex.track.video'|trans }} {{ s.videoDecision ?? '—' }} ·
        {{ 'dashboard.plex.track.audio'|trans }} {{ s.audioDecision ?? '—' }} ·
        {{ 'dashboard.plex.track.subtitle'|trans }} {{ s.subtitleDecision ?? '—' }}
      </div>
    {% endif %}
    <div class="progress plex-progress">
      <div class="progress-bar" style="width: {{ s.progressPercent }}%" role="progressbar"
           aria-valuenow="{{ s.progressPercent }}" aria-valuemin="0" aria-valuemax="100"></div>
    </div>
  </div>
</div>
```

- [ ] **Step 2: Create `_plex_history_row.html.twig`** (takes `h`; uses the new filter)

```twig
{# One watch-history row. Expects `h` (a normalized history row). #}
{% import '_icons.html.twig' as ico %}
<div class="plex-session {% if h.ratingKey %}plex-clickable{% endif %}"
     {% if h.ratingKey %}data-plex-rating-key="{{ h.ratingKey }}" role="button" tabindex="0"{% endif %}>
  <div class="plex-poster">
    {{ ico.icon(h.mediaType == 'movie' ? 'movie' : 'device-tv', '', 22) }}
    {% if h.posterPath %}<img class="plex-poster-img" src="{{ path('app_tautulli_api_image', {img: h.posterPath}) }}" alt="" loading="lazy">{% endif %}
  </div>
  <div class="plex-session-main">
    <div class="plex-session-title text-truncate">
      {% if h.mediaType == 'episode' and h.grandparentTitle %}{{ h.grandparentTitle }}{% else %}{{ h.title }}{% endif %}
      {% if h.year %}<span class="plex-session-year">({{ h.year }})</span>{% endif %}
    </div>
    <div class="plex-session-meta text-truncate">
      {{ [h.userDisplayName, h.watchedAt|relative_date]|filter(v => v is not null)|join(' · ') }}
    </div>
    {% if h.percentComplete > 0 %}
      <div class="progress plex-progress"><div class="progress-bar" style="width: {{ h.percentComplete }}%"></div></div>
    {% endif %}
  </div>
</div>
```

- [ ] **Step 3: Create `_plex_info_modal.html.twig`** (shell + trigger + delegated handler)

Move the modal markup and the modal JS out of `dashboard/index.html.twig` into this partial:

```twig
{# Shared Plex info modal: shell + hidden data-API trigger + the delegated
   open/keydown handler. Included by the dashboard and the Tautulli page.
   Tabler doesn't expose window.bootstrap, so we click a hidden data-bs-toggle
   trigger (same pattern as the usenet/qBit detail modals). #}
<button type="button" class="d-none" data-plex-modal-trigger data-bs-toggle="modal" data-bs-target="#plex-info-modal"></button>
<div class="modal modal-blur fade" id="plex-info-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content" data-plex-modal-body>
      <div class="modal-body text-center py-5"><span class="spinner-border"></span></div>
    </div>
  </div>
</div>
<script>
(function () {
  if (window._plexModalDelegated) return;
  window._plexModalDelegated = true;
  function openPlexModal(ratingKey, player, device) {
    var modalEl = document.getElementById('plex-info-modal');
    var trigger = document.querySelector('[data-plex-modal-trigger]');
    if (!modalEl || !trigger) return;
    var body = modalEl.querySelector('[data-plex-modal-body]');
    body.innerHTML = '<div class="modal-body text-center py-5"><span class="spinner-border"></span></div>';
    trigger.click();
    var url = '/tautulli/api/metadata/' + encodeURIComponent(ratingKey)
      + '?player=' + encodeURIComponent(player || '') + '&device=' + encodeURIComponent(device || '');
    fetch(url, { headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.text() : ''; })
      .then(function (html) { body.innerHTML = html || '<div class="modal-body text-center text-secondary py-5">—</div>'; })
      .catch(function () { body.innerHTML = '<div class="modal-body text-center text-secondary py-5">—</div>'; });
  }
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-plex-rating-key]');
    if (!t) return;
    openPlexModal(t.getAttribute('data-plex-rating-key'), t.getAttribute('data-plex-player'), t.getAttribute('data-plex-device'));
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    var t = e.target.closest('[data-plex-rating-key]');
    if (!t) return;
    e.preventDefault();
    openPlexModal(t.getAttribute('data-plex-rating-key'), t.getAttribute('data-plex-player'), t.getAttribute('data-plex-device'));
  });
})();
</script>
```

- [ ] **Step 4: Create `_plex_styles.html.twig`** (the `.plex-*` rules, shared)

Cut the `.plex-*` block (currently `dashboard/index.html.twig` lines 459–479, from `.plex-summary {` through `.progress.plex-progress {…}`) verbatim into this new file, wrapped in its own `<style>`:

```twig
<style>
  .plex-summary { display: flex; align-items: center; flex-wrap: wrap; gap: .75rem 1.25rem; padding-bottom: .9rem; margin-bottom: .9rem; border-bottom: 1px solid rgba(148,163,184,.14); }
  .plex-stat { display: flex; align-items: baseline; gap: .4rem; }
  .plex-stat-value { font-size: 1.5rem; font-weight: 700; line-height: 1; }
  .plex-stat-label { font-size: .72rem; color: var(--tblr-secondary); text-transform: uppercase; letter-spacing: .03em; }
  .plex-stat-badges { display: flex; flex-wrap: wrap; gap: .35rem; }
  .plex-bw { display: flex; flex-wrap: wrap; align-items: center; gap: .25rem .9rem; margin-left: auto; font-size: .8rem; }
  .plex-bw > span:first-child { font-weight: 600; }
  .plex-sessions { display: flex; flex-direction: column; gap: .7rem; }
  .plex-session { display: flex; gap: .8rem; align-items: flex-start; }
  .plex-poster { position: relative; overflow: hidden; flex-shrink: 0; width: 42px; height: 63px; border-radius: 6px; display: flex; align-items: center; justify-content: center; background: var(--tblr-bg-surface-secondary); color: var(--tblr-secondary); border: 1px solid rgba(148,163,184,.18); }
  .plex-poster-img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
  .plex-tabs { margin-bottom: .75rem; }
  .plex-clickable { cursor: pointer; }
  .plex-session-title.plex-clickable:hover { color: var(--tblr-primary); }
  .plex-session-main { flex: 1 1 auto; min-width: 0; }
  .plex-session-title { font-size: .88rem; font-weight: 600; }
  .plex-session-year { font-weight: 400; color: var(--tblr-secondary); }
  .plex-session-meta { font-size: .75rem; color: var(--tblr-secondary); margin-top: .1rem; }
  .plex-badges { display: flex; flex-wrap: wrap; gap: .3rem; margin: .4rem 0 .35rem; }
  .plex-decisions { font-size: .68rem; margin-bottom: .35rem; }
  .progress.plex-progress { height: 5px; background: var(--tblr-bg-surface-secondary); }
</style>
```

- [ ] **Step 5: Rewire `_plex_activity.html.twig` to use the partials**

In `_plex_activity.html.twig`, replace the inline now-pane session loop (the `{% for s in plex.sessions %}` … `{% endfor %}` block and its per-session `{% set %}` lines) with:

```twig
    {% for s in plex.sessions %}
      {% include 'dashboard/_plex_session_card.html.twig' with {s: s} only %}
    {% endfor %}
```

And replace the recent-pane history loop with:

```twig
      {% for h in plex_history %}
        {% include 'dashboard/_plex_history_row.html.twig' with {h: h} only %}
      {% endfor %}
```

(The surrounding summary block, error states, tab `<ul>`, and pane `<div>`s stay unchanged.)

- [ ] **Step 6: Rewire `dashboard/index.html.twig`**

1. Remove the inline `.plex-*` CSS lines (459–479) from the `<style>` block; add `{% include 'dashboard/_plex_styles.html.twig' %}` immediately after the `</style>` tag (or anywhere in the body).
2. Remove the inline info-modal shell + hidden trigger (the `{# Shared Plex info modal … #}` comment, the `<button … data-plex-modal-trigger …>`, and the `<div class="modal … id="plex-info-modal">…</div>`); replace with `{% include 'dashboard/_plex_info_modal.html.twig' %}` at the same spot.
3. In the dashboard JS IIFE, delete the `openPlexModal` function and the two `document.addEventListener('click'…)` / `('keydown'…)` blocks that reference `[data-plex-rating-key]` (now provided by the modal partial). **Keep** `refreshPlex`, `applyPlexTab`, the tab click handler, and the timers.

- [ ] **Step 7: Simplify `DashboardController::widgetPlex`** (drop the now-redundant `when` mapping)

Since `_plex_history_row.html.twig` now formats time via `watchedAt|relative_date`, the controller no longer needs to compute `when`. Replace lines 277–283 (the `$now` + `array_map` block) so the render passes the cached history directly:

```php
        $history = $this->cached('plex_history', fn() => $this->tautulli->getHistory(8));

        $streaming = ($activity['streamCount'] ?? 0) > 0;

        return $this->render('dashboard/_plex_activity.html.twig', [
            'plex'        => $activity,
            'plex_history'=> $history,
            'plex_tab'    => $streaming ? 'now' : 'recent',
        ]);
```

(Leave `relativeDate()` defined in `DashboardController` — other widgets still use it.)

- [ ] **Step 8: Lint templates**

Run: `docker exec prismarr php bin/console lint:twig templates/dashboard`
Expected: all valid.

- [ ] **Step 9: Commit**

```bash
git add symfony/templates/dashboard/_plex_session_card.html.twig symfony/templates/dashboard/_plex_history_row.html.twig symfony/templates/dashboard/_plex_info_modal.html.twig symfony/templates/dashboard/_plex_styles.html.twig symfony/templates/dashboard/_plex_activity.html.twig symfony/templates/dashboard/index.html.twig symfony/src/Controller/DashboardController.php
git commit -m "refactor(tautulli): extract shared Plex partials (card, row, modal, styles)"
```

---

### Task 7: `TautulliController` endpoints + fragment templates

**Files:**
- Modify: `symfony/src/Controller/TautulliController.php`
- Create: `symfony/templates/tautulli/_now_playing.html.twig`
- Create: `symfony/templates/tautulli/_stats.html.twig`
- Create: `symfony/templates/tautulli/_history_rows.html.twig`
- Create: `symfony/templates/tautulli/_libraries.html.twig`

- [ ] **Step 1: Update the class docblock + add the endpoints**

In `TautulliController.php`, update the class docblock's "Only `get_activity` is exposed" sentence to note the page also exposes read-only `get_metadata`, `get_history`, `get_home_stats`, `get_plays_by_date`, `get_libraries` (no mutating commands). Then add these actions before the closing `}` (the class already injects `private readonly TautulliClient $tautulli` and uses `Request`, `Response`, `JsonResponse`, `Route`):

```php
    /**
     * GET /tautulli — the full Plex activity page. Server-renders the cheap
     * "Now Playing" section; heavier sections hydrate client-side. Guarded by
     * ServiceRouteGuardSubscriber when Tautulli is unconfigured.
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        set_time_limit(60);
        try {
            $activity = $this->tautulli->getActivity();
        } catch (\Throwable) {
            $activity = ['enabled' => true, 'configured' => true, 'connected' => false, 'error' => 'unreachable', 'streamCount' => 0, 'sessions' => []];
        }
        return $this->render('tautulli/index.html.twig', ['plex' => $activity]);
    }

    /** GET /tautulli/api/now-playing — live stream cards fragment (polled). */
    #[Route('/api/now-playing', name: 'api_now_playing', methods: ['GET'])]
    public function apiNowPlaying(): Response
    {
        try {
            $activity = $this->tautulli->getActivity();
        } catch (\Throwable) {
            $activity = ['connected' => false, 'error' => 'unreachable', 'sessions' => []];
        }
        return $this->render('tautulli/_now_playing.html.twig', ['plex' => $activity]);
    }

    /** GET /tautulli/api/stats?range=30 — watch-stats tiles fragment. */
    #[Route('/api/stats', name: 'api_stats', methods: ['GET'])]
    public function apiStats(Request $request): Response
    {
        try {
            $stats = $this->tautulli->getHomeStats((int) $request->query->get('range', 30));
        } catch (\Throwable) {
            $stats = ['topMovies' => [], 'topShows' => [], 'topUsers' => [], 'topPlatforms' => []];
        }
        return $this->render('tautulli/_stats.html.twig', ['stats' => $stats]);
    }

    /** GET /tautulli/api/plays?range=30 — plays-per-day series as JSON (Chart.js). */
    #[Route('/api/plays', name: 'api_plays', methods: ['GET'])]
    public function apiPlays(Request $request): JsonResponse
    {
        try {
            return $this->json($this->tautulli->getPlaysByDate((int) $request->query->get('range', 30)));
        } catch (\Throwable) {
            return $this->json(['categories' => [], 'series' => []]);
        }
    }

    /** GET /tautulli/api/history?length=25&start=0 — history rows fragment. */
    #[Route('/api/history', name: 'api_history', methods: ['GET'])]
    public function apiHistory(Request $request): Response
    {
        try {
            $rows = $this->tautulli->getHistory(
                (int) $request->query->get('length', 25),
                (int) $request->query->get('start', 0),
            );
        } catch (\Throwable) {
            $rows = [];
        }
        return $this->render('tautulli/_history_rows.html.twig', ['plex_history' => $rows]);
    }

    /** GET /tautulli/api/libraries — library count cards fragment. */
    #[Route('/api/libraries', name: 'api_libraries', methods: ['GET'])]
    public function apiLibraries(): Response
    {
        try {
            $libraries = $this->tautulli->getLibraries();
        } catch (\Throwable) {
            $libraries = [];
        }
        return $this->render('tautulli/_libraries.html.twig', ['libraries' => $libraries]);
    }
```

- [ ] **Step 2: Create `tautulli/_now_playing.html.twig`**

```twig
{# Live stream cards for the activity page. `plex` is the getActivity() envelope. #}
{% if plex.error or not plex.connected or plex.sessions is empty %}
  <div class="text-center text-secondary py-4">{{ 'dashboard.plex.empty'|trans }}</div>
{% else %}
  <div class="plex-sessions">
    {% for s in plex.sessions %}
      {% include 'dashboard/_plex_session_card.html.twig' with {s: s} only %}
    {% endfor %}
  </div>
{% endif %}
```

- [ ] **Step 3: Create `tautulli/_history_rows.html.twig`**

```twig
{# History rows fragment. `plex_history` is a list of normalized rows. Returns
   nothing when empty so the "Load more" JS can detect the end of the list. #}
{% for h in plex_history %}
  {% include 'dashboard/_plex_history_row.html.twig' with {h: h} only %}
{% endfor %}
```

- [ ] **Step 4: Create `tautulli/_stats.html.twig`**

```twig
{# Watch-statistics tiles. `stats` = {topMovies, topShows, topUsers, topPlatforms}. #}
{% import '_icons.html.twig' as ico %}
{% if stats.topMovies is empty and stats.topShows is empty and stats.topUsers is empty and stats.topPlatforms is empty %}
  <div class="text-center text-secondary py-4">{{ 'tautulli.stats.empty'|trans }}</div>
{% else %}
<div class="row row-cards">
  {# Most-watched movies + shows: clickable poster tiles #}
  {% for group in [{title: 'tautulli.stats.top_movie', items: stats.topMovies, type: 'movie'}, {title: 'tautulli.stats.top_show', items: stats.topShows, type: 'episode'}] %}
    {% if group.items is not empty %}
    <div class="col-md-6 col-xl-3">
      <div class="card h-100">
        <div class="card-header"><h3 class="card-title">{{ group.title|trans }}</h3></div>
        <div class="card-body">
          {% for it in group.items %}
            <div class="plex-stat-row {% if it.ratingKey %}plex-clickable{% endif %}"
                 {% if it.ratingKey %}data-plex-rating-key="{{ it.ratingKey }}" role="button" tabindex="0"{% endif %}>
              <div class="plex-poster plex-poster-sm">
                {{ ico.icon(group.type == 'movie' ? 'movie' : 'device-tv', '', 18) }}
                {% if it.posterPath %}<img class="plex-poster-img" src="{{ path('app_tautulli_api_image', {img: it.posterPath}) }}" alt="" loading="lazy">{% endif %}
              </div>
              <div class="plex-stat-row-main">
                <div class="text-truncate">{{ it.title }}{% if it.year is defined and it.year %} <span class="plex-session-year">({{ it.year }})</span>{% endif %}</div>
                <div class="text-secondary small">{{ 'tautulli.stats.plays_count'|trans({count: it.plays}) }}</div>
              </div>
            </div>
          {% endfor %}
        </div>
      </div>
    </div>
    {% endif %}
  {% endfor %}

  {# Top users + platforms: simple labelled lists #}
  {% for group in [{title: 'tautulli.stats.top_user', items: stats.topUsers, key: 'userDisplayName'}, {title: 'tautulli.stats.top_platform', items: stats.topPlatforms, key: 'platform'}] %}
    {% if group.items is not empty %}
    <div class="col-md-6 col-xl-3">
      <div class="card h-100">
        <div class="card-header"><h3 class="card-title">{{ group.title|trans }}</h3></div>
        <div class="card-body">
          {% for it in group.items %}
            <div class="d-flex justify-content-between align-items-center py-1">
              <span class="text-truncate">{{ attribute(it, group.key) }}</span>
              <span class="badge bg-secondary-lt ms-2">{{ 'tautulli.stats.plays_count'|trans({count: it.plays}) }}</span>
            </div>
          {% endfor %}
        </div>
      </div>
    </div>
    {% endif %}
  {% endfor %}
</div>
{% endif %}
```

- [ ] **Step 5: Create `tautulli/_libraries.html.twig`**

```twig
{# Library count cards. `libraries` = list of {name, type, count, childCount}. #}
{% import '_icons.html.twig' as ico %}
{% if libraries is empty %}
  <div class="text-center text-secondary py-4">{{ 'tautulli.libraries.empty'|trans }}</div>
{% else %}
<div class="row row-cards">
  {% for lib in libraries %}
    <div class="col-6 col-md-4 col-xl-3">
      <div class="card card-sm">
        <div class="card-body d-flex align-items-center gap-2">
          {{ ico.icon(lib.type == 'movie' ? 'movie' : (lib.type == 'show' ? 'device-tv' : 'folder'), 'text-secondary', 22) }}
          <div>
            <div class="fw-bold text-truncate">{{ lib.name }}</div>
            <div class="text-secondary small">
              {{ 'tautulli.libraries.items'|trans({count: lib.count}) }}{% if lib.childCount %} · {{ 'tautulli.libraries.episodes'|trans({count: lib.childCount}) }}{% endif %}
            </div>
          </div>
        </div>
      </div>
    </div>
  {% endfor %}
</div>
{% endif %}
```

- [ ] **Step 6: Verify routes register + lint templates**

Run: `docker exec prismarr php bin/console debug:router | docker exec -i prismarr grep app_tautulli`
Expected: shows `app_tautulli_index`, `app_tautulli_api_now_playing`, `app_tautulli_api_stats`, `app_tautulli_api_plays`, `app_tautulli_api_history`, `app_tautulli_api_libraries` (plus the existing activity/image/metadata routes).
Run: `docker exec prismarr php bin/console lint:twig templates/tautulli`
Expected: all valid. (The `index.html.twig` page is created in Task 8; if lint complains about a missing `tautulli/index.html.twig`, that's expected until Task 8 — lint each fragment individually here, or proceed to Task 8 before linting the directory.)

- [ ] **Step 7: Commit**

```bash
git add symfony/src/Controller/TautulliController.php symfony/templates/tautulli/_now_playing.html.twig symfony/templates/tautulli/_stats.html.twig symfony/templates/tautulli/_history_rows.html.twig symfony/templates/tautulli/_libraries.html.twig
git commit -m "feat(tautulli): add activity-page fragment + JSON endpoints"
```

---

### Task 8: the activity page template + JS (toggle, poll, load-more, chart)

**Files:**
- Create: `symfony/templates/tautulli/index.html.twig`

- [ ] **Step 1: Create the page template**

```twig
{% extends 'base.html.twig' %}
{% block title %}{{ 'tautulli.title'|trans }}{% endblock %}

{% block stylesheets %}
{{ parent() }}
{% include 'dashboard/_plex_styles.html.twig' %}
<style>
  .plex-poster-sm { width: 34px; height: 51px; }
  .plex-stat-row { display: flex; gap: .55rem; align-items: center; padding: .25rem 0; }
  .plex-stat-row-main { min-width: 0; }
  .plex-range .btn.active { background: var(--tblr-primary); color: #fff; }
  #tautulli-plays-wrap { position: relative; height: 240px; }
</style>
{% endblock %}

{% block content %}
<div class="page-header d-print-none">
  <div class="row align-items-center">
    <div class="col"><h2 class="page-title">{{ 'tautulli.title'|trans }}</h2></div>
    <div class="col-auto">
      <div class="btn-group plex-range" role="group" data-tautulli-range>
        <button type="button" class="btn btn-sm" data-range="7">{{ 'tautulli.range.7d'|trans }}</button>
        <button type="button" class="btn btn-sm active" data-range="30">{{ 'tautulli.range.30d'|trans }}</button>
        <button type="button" class="btn btn-sm" data-range="90">{{ 'tautulli.range.90d'|trans }}</button>
      </div>
    </div>
  </div>
</div>

<div class="page-body">
  <div class="container-xl">

    {# 1. Now Playing — server-rendered, then polled #}
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">{{ 'tautulli.section.now_playing'|trans }}</h3></div>
      <div class="card-body" data-tautulli-now>
        {% include 'tautulli/_now_playing.html.twig' with {plex: plex} only %}
      </div>
    </div>

    {# 2. Statistics #}
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">{{ 'tautulli.section.stats'|trans }}</h3></div>
      <div class="card-body" data-tautulli-stats>
        <div class="text-center text-secondary py-4"><span class="spinner-border"></span></div>
      </div>
    </div>

    {# 3. Plays over time #}
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">{{ 'tautulli.section.plays'|trans }}</h3></div>
      <div class="card-body">
        <div id="tautulli-plays-wrap"><canvas id="tautulli-plays"></canvas></div>
        <div data-tautulli-plays-empty class="text-center text-secondary py-4" style="display:none;">{{ 'tautulli.plays.empty'|trans }}</div>
      </div>
    </div>

    {# 4. History + Libraries #}
    <div class="row row-cards mb-3">
      <div class="col-lg-7">
        <div class="card h-100">
          <div class="card-header"><h3 class="card-title">{{ 'tautulli.section.history'|trans }}</h3></div>
          <div class="card-body">
            <div class="plex-sessions" data-tautulli-history>
              <div class="text-center text-secondary py-4"><span class="spinner-border"></span></div>
            </div>
            <div class="text-center mt-3">
              <button type="button" class="btn btn-sm btn-outline-secondary" data-tautulli-history-more style="display:none;">{{ 'tautulli.history.load_more'|trans }}</button>
            </div>
          </div>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="card h-100">
          <div class="card-header"><h3 class="card-title">{{ 'tautulli.section.libraries'|trans }}</h3></div>
          <div class="card-body" data-tautulli-libraries>
            <div class="text-center text-secondary py-4"><span class="spinner-border"></span></div>
          </div>
        </div>
      </div>
    </div>

  </div>
</div>

{% include 'dashboard/_plex_info_modal.html.twig' %}
{% endblock %}

{% block javascripts %}
{{ parent() }}
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
(function () {
  var HISTORY_PAGE = 25;
  var range = 30;
  var historyStart = 0;
  var chart = null;

  function frag(sel) { return document.querySelector(sel); }

  function loadFragment(url, target) {
    return fetch(url, { headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.text() : ''; })
      .then(function (html) { if (target) target.innerHTML = html; return html; })
      .catch(function () { return ''; });
  }

  // Now Playing — initial render is server-side; poll every 10s.
  function refreshNow() {
    if (document.hidden) return;
    loadFragment('/tautulli/api/now-playing', frag('[data-tautulli-now]'));
  }

  // Stats — fetch on load + on range change.
  function refreshStats() {
    loadFragment('/tautulli/api/stats?range=' + range, frag('[data-tautulli-stats]'));
  }

  // Libraries — once.
  function refreshLibraries() {
    loadFragment('/tautulli/api/libraries', frag('[data-tautulli-libraries]'));
  }

  // History — replace on (re)load, append on "Load more".
  function loadHistory(append) {
    if (!append) historyStart = 0;
    var url = '/tautulli/api/history?length=' + HISTORY_PAGE + '&start=' + historyStart;
    fetch(url, { headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.text() : ''; })
      .then(function (html) {
        var box = frag('[data-tautulli-history]');
        var more = frag('[data-tautulli-history-more]');
        var trimmed = (html || '').trim();
        if (!append) box.innerHTML = trimmed || '';
        else if (trimmed) box.insertAdjacentHTML('beforeend', trimmed);
        var rowCount = (trimmed.match(/class="plex-session/g) || []).length;
        if (!append && !rowCount) box.innerHTML = '<div class="text-center text-secondary py-4">—</div>';
        historyStart += HISTORY_PAGE;
        if (more) more.style.display = rowCount >= HISTORY_PAGE ? '' : 'none';
      })
      .catch(function () {});
  }

  // Plays graph — fetch JSON, (re)draw Chart.js.
  var SERIES_COLORS = { 'TV': '#22c55e', 'Movies': '#6366f1', 'Music': '#f59e0b' };
  function refreshPlays() {
    fetch('/tautulli/api/plays?range=' + range, { headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        var wrap = frag('#tautulli-plays-wrap');
        var empty = frag('[data-tautulli-plays-empty]');
        var hasData = d && d.series && d.series.length && d.categories && d.categories.length;
        if (wrap) wrap.style.display = hasData ? '' : 'none';
        if (empty) empty.style.display = hasData ? 'none' : '';
        if (!hasData) { if (chart) { chart.destroy(); chart = null; } return; }
        var datasets = d.series.map(function (s) {
          var c = SERIES_COLORS[s.name] || '#94a3b8';
          return { label: s.name, data: s.data, backgroundColor: c, borderColor: c, borderRadius: 3, borderSkipped: false };
        });
        if (chart) chart.destroy();
        chart = new Chart(frag('#tautulli-plays'), {
          type: 'bar',
          data: { labels: d.categories, datasets: datasets },
          options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: datasets.length > 1 } },
            scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } } }
          }
        });
      })
      .catch(function () {});
  }

  // Range toggle.
  var rangeBar = frag('[data-tautulli-range]');
  if (rangeBar) {
    rangeBar.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-range]');
      if (!btn) return;
      range = parseInt(btn.getAttribute('data-range'), 10) || 30;
      rangeBar.querySelectorAll('[data-range]').forEach(function (b) { b.classList.toggle('active', b === btn); });
      refreshStats();
      refreshPlays();
    });
  }

  // Initial hydration.
  refreshStats();
  refreshPlays();
  refreshLibraries();
  loadHistory(false);
  var moreBtn = frag('[data-tautulli-history-more]');
  if (moreBtn) moreBtn.addEventListener('click', function () { loadHistory(true); });

  // Now-playing poll (singleton across Turbo re-exec).
  if (window._tautulliNowTimer) clearInterval(window._tautulliNowTimer);
  window._tautulliNowTimer = setInterval(refreshNow, 10000);
  document.addEventListener('turbo:before-render', function () {
    if (window._tautulliNowTimer) { clearInterval(window._tautulliNowTimer); window._tautulliNowTimer = null; }
  });
})();
</script>
{% endblock %}
```

> Note on the history-end heuristic: the "Load more" button hides when a page returns fewer than `HISTORY_PAGE` rows. Row count is detected by counting `class="plex-session` occurrences in the returned fragment — the history-row partial's root element uses that class.

- [ ] **Step 2: Lint templates**

Run: `docker exec prismarr php bin/console lint:twig templates/tautulli`
Expected: all valid.

- [ ] **Step 3: Verify the page renders (configured instance)**

Run: `docker exec prismarr php bin/console debug:router app_tautulli_index`
Expected: `GET /tautulli`. (Full visual verification happens after the image rebuild — see Task 12.)

- [ ] **Step 4: Commit**

```bash
git add symfony/templates/tautulli/index.html.twig
git commit -m "feat(tautulli): add the full Plex activity page (sections + chart + JS)"
```

---

### Task 9: sidebar registration, nav entry & route guard

**Files:**
- Modify: `symfony/src/Twig/ConfigExtension.php`
- Modify: `symfony/src/EventSubscriber/ServiceRouteGuardSubscriber.php`
- Modify: `symfony/templates/base.html.twig`

- [ ] **Step 1: Register Tautulli as a flat service**

In `ConfigExtension.php`, add Tautulli to `SERVICE_KEYS`:

```php
    private const SERVICE_KEYS = [
        'tmdb'        => 'tmdb_api_key',
        'prowlarr'    => 'prowlarr_api_key',
        'jellyseerr'  => 'jellyseerr_api_key',
        'qbittorrent' => 'qbittorrent_url',
        'sabnzbd'     => 'sabnzbd_url',
        'nzbget'      => 'nzbget_url',
        'gluetun'     => 'gluetun_url',
        'tautulli'    => 'tautulli_url',
    ];
```

- [ ] **Step 2: Add the route-guard rule (page route only)**

In `ServiceRouteGuardSubscriber.php`, add to `RULES` (keyed on `app_tautulli_index` so the `app_tautulli_api_*` endpoints stay unguarded and keep serving fail-open JSON):

```php
        'app_tautulli_index' => ['service' => 'Tautulli', 'service_id' => 'tautulli', 'keys' => ['tautulli_url', 'tautulli_api_key'], 'wizard' => 'admin_settings_index', 'index' => 'app_tautulli_index'],
```

- [ ] **Step 3: Add the sidebar nav entry**

In `base.html.twig`, add a nav `<li>` near the other monitoring entries (e.g. right after the qBittorrent `{% endif %}` at the qbittorrent block, or wherever Plex-adjacent entries sit). Active when on the page route:

```twig
            {% if service_visible_in_sidebar('tautulli') %}
            <li class="nav-item">
              <a class="nav-link {{ app.request.attributes.get('_route') == 'app_tautulli_index' ? 'active' }}"
                 href="{{ path('app_tautulli_index') }}">
                <span class="nav-link-icon d-md-none d-lg-inline-block">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                       fill="none" stroke="#e5a00d" stroke-width="2"
                       stroke-linecap="round" stroke-linejoin="round" class="icon">
                    <path d="M3 12h4l3 8l4 -16l3 8h4"/>
                  </svg>
                </span>
                <span class="nav-link-title">Tautulli</span>
              </a>
            </li>
            {% endif %}
```

> The icon is a pulse/activity line in Tautulli's gold (`#e5a00d`); swap for the official Tautulli logo SVG later if desired.

- [ ] **Step 4: Lint + verify visibility logic**

Run: `docker exec prismarr php bin/console lint:twig templates/base.html.twig`
Expected: valid.
Run: `docker exec prismarr php bin/console cache:clear`
Expected: success (picks up the new guard rule + Twig const).

- [ ] **Step 5: Commit**

```bash
git add symfony/src/Twig/ConfigExtension.php symfony/src/EventSubscriber/ServiceRouteGuardSubscriber.php symfony/templates/base.html.twig
git commit -m "feat(tautulli): sidebar nav entry + page route guard"
```

---

### Task 10: translations (en + fr)

**Files:**
- Modify: `symfony/translations/messages+intl-icu.en.yaml`
- Modify: `symfony/translations/messages+intl-icu.fr.yaml`

- [ ] **Step 1: Add the `tautulli:` block (en)**

Add a top-level `tautulli:` mapping (sibling of `dashboard:`) in `messages+intl-icu.en.yaml`:

```yaml
tautulli:
  title: Plex Activity
  range:
    7d: 7 days
    30d: 30 days
    90d: 90 days
  section:
    now_playing: Now playing
    stats: Statistics
    plays: Plays over time
    history: History
    libraries: Libraries
  stats:
    top_movie: Most watched movies
    top_show: Most watched shows
    top_user: Most active users
    top_platform: Top platforms
    plays_count: '{count, plural, one {# play} other {# plays}}'
    empty: No statistics for this period
  plays:
    empty: No play data for this period
  history:
    load_more: Load more
  libraries:
    empty: No libraries
    items: '{count, plural, one {# item} other {# items}}'
    episodes: '{count, plural, one {# episode} other {# episodes}}'
```

- [ ] **Step 2: Add the `tautulli:` block (fr)**

In `messages+intl-icu.fr.yaml`:

```yaml
tautulli:
  title: Activité Plex
  range:
    7d: 7 jours
    30d: 30 jours
    90d: 90 jours
  section:
    now_playing: En cours
    stats: Statistiques
    plays: Lectures dans le temps
    history: Historique
    libraries: Bibliothèques
  stats:
    top_movie: Films les plus regardés
    top_show: Séries les plus regardées
    top_user: Utilisateurs les plus actifs
    top_platform: Plateformes principales
    plays_count: '{count, plural, one {# lecture} other {# lectures}}'
    empty: Aucune statistique pour cette période
  plays:
    empty: Aucune donnée de lecture pour cette période
  history:
    load_more: Charger plus
  libraries:
    empty: Aucune bibliothèque
    items: '{count, plural, one {# élément} other {# éléments}}'
    episodes: '{count, plural, one {# épisode} other {# épisodes}}'
```

- [ ] **Step 3: Lint translations**

Run: `docker exec prismarr php bin/console lint:yaml translations`
Expected: all valid.

- [ ] **Step 4: Commit**

```bash
git add symfony/translations/messages+intl-icu.en.yaml symfony/translations/messages+intl-icu.fr.yaml
git commit -m "i18n(tautulli): strings for the activity page"
```

---

### Task 11: controller smoke test

**Files:**
- Modify: `symfony/tests/Controller/DashboardControllerTest.php` (or create `symfony/tests/Controller/TautulliControllerTest.php` if the project keeps one test class per controller — check the existing layout and match it)

- [ ] **Step 1: Write the test**

Mirror the existing controller-test setup (authenticated client). Assert the JSON endpoints answer 200 with neutral shapes when Tautulli is unconfigured (the test env has no `tautulli_url`), and that the page route is guarded (redirects when unconfigured). Use the existing test's auth helper — check how `DashboardControllerTest` logs in and reuse that pattern:

```php
    public function testPlaysEndpointReturnsNeutralJsonWhenUnconfigured(): void
    {
        $client = $this->authenticatedClient(); // reuse the existing helper/pattern
        $client->request('GET', '/tautulli/api/plays?range=30');
        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame([], $data['categories']);
        self::assertSame([], $data['series']);
    }

    public function testRangeParamIsClampedServerSide(): void
    {
        $client = $this->authenticatedClient();
        // An out-of-range value must not error; neutral shape still returned.
        $client->request('GET', '/tautulli/api/plays?range=9999');
        self::assertResponseIsSuccessful();
    }

    public function testActivityPageGuardedWhenUnconfigured(): void
    {
        $client = $this->authenticatedClient();
        $client->request('GET', '/tautulli');
        // ServiceRouteGuardSubscriber bounces unconfigured services to settings.
        self::assertResponseRedirects();
    }
```

> If the test environment DOES configure Tautulli, drop `testActivityPageGuardedWhenUnconfigured` and instead assert the page renders 200 with the "Now playing" section heading. Match the test harness reality — check `symfony/tests` config and the existing Tautulli/dashboard tests first.

- [ ] **Step 2: Run the test**

Run: `docker exec -e APP_ENV=test prismarr vendor/bin/phpunit --filter "Tautulli|PlaysEndpoint|ActivityPage"`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add symfony/tests/Controller/
git commit -m "test(tautulli): smoke-test activity page endpoints + guard"
```

---

### Task 12: CHANGELOG + full check + push

**Files:**
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Extend the unreleased Tautulli entry**

Append to the existing unreleased Tautulli bullet in `CHANGELOG.md`:

```
Tautulli now has its own sidebar entry opening a full **Plex Activity** page: live streams, watch statistics (most-watched movies/shows, most-active users, top platforms with a 7/30/90-day toggle), a plays-over-time chart, paginated watch history, and library counts — every title clickable into the same info modal. All data is fetched server-side via read-only Tautulli commands (`get_home_stats`, `get_plays_by_date`, `get_libraries`, `get_history`) and sanitized before it reaches the browser.
```

- [ ] **Step 2: Run the full check suite in-container**

Run: `make check`
Expected: PHP lint OK, all Twig valid, all YAML valid, PHPUnit green.

- [ ] **Step 3: Commit + push**

```bash
git add CHANGELOG.md
git commit -m "docs(changelog): note the Tautulli activity page"
git push origin main
```

- [ ] **Step 4: Verify CI + image, then manual check**

After push: confirm CI (`make check`) is green and the GHCR `:latest` image rebuilt, then Force Update on Unraid and verify end-to-end:
- Sidebar shows a "Tautulli" entry only when configured; hiding it via the sidebar-hide preference works.
- `/tautulli` renders all five sections; each fails open (empty state) if Tautulli is briefly down.
- The 7/30/90-day toggle updates the stats tiles and the chart.
- "Load more" appends history and disappears at the end of the list.
- The info modal opens from a live card, a history row, AND a most-watched tile.
- The dashboard widget is unchanged (tabs, 10s poll, modal still work) after the partial extraction.

---

## Self-Review notes

- **Spec coverage:** Sidebar entry + visibility + guard = Task 9 (+ Task 7 page route). Client data methods + sanitized shapes = Tasks 1–4. Endpoints = Task 7. Page layout (stacked) + toggle + chart + load-more + modal reuse = Task 8. Shared-partial extraction (Component 4) = Task 6. `relative_date` sharing = Task 5. Security/fail-open = built into every getter (empty shape) and every action (try/catch) — Tasks 1–4, 7. Testing = Tasks 1–5 (unit) + Task 11 (controller). i18n = Task 10. CHANGELOG/CI/manual = Task 12. All spec sections mapped.
- **Type consistency:** stats keys `topMovies/topShows/topUsers/topPlatforms`, each row `{ratingKey,title,(year),posterPath,plays}` / `{userDisplayName,plays}` / `{platform,plays}`; plays `{categories:list<string>, series:[{name,data:list<int>}]}`; libraries `{name,type,count,childCount}`; history reuses `normalizeHistory` (`ratingKey,mediaType,title,grandparentTitle,year,posterPath,userDisplayName,watchedAt,percentComplete`). Route names `app_tautulli_index|api_now_playing|api_stats|api_plays|api_history|api_libraries`. Trigger attribute `data-plex-rating-key` consistent across the session card, history row, and stat tiles, handled by the shared modal partial. Filter `relative_date` used by `_plex_history_row`.
- **Helpers reused:** `self::str`, `self::strList`, `self::pickPoster`, `self::normalizeHistory`, `DashboardController::cached`; Chart.js CDN + `new Chart()` mirror `radarr/stats.html.twig`; nav/guard/SERVICE_KEYS mirror existing service wiring.
- **Risk note (Task 6):** the partial extraction touches working dashboard code. Each rewired include reproduces the existing markup verbatim; Step 8 lints and the Task 12 manual check confirms the widget is unchanged.
