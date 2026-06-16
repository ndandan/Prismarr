# Tautulli Activity Page v2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Declutter the Tautulli "Plex Activity" page (remove Recently Added, dense History grid, slim Libraries, drop "Total" chart series) and add three graphs (Media⇄Stream plays toggle, hour-of-day + day-of-week activity, platform×stream-type problem clients).

**Architecture:** All new charts reuse the existing `{categories, series}` contract, the pure `normalizePlaysByDate()` transform, the 7/30/90-day range toggle, and a generalized `drawStackedBar` JS helper. New endpoints mirror `/api/plays` (fail-open JSON). No new dependencies.

**Tech Stack:** Symfony 6 (PHP), Twig, PHPUnit, vanilla JS, self-hosted Chart.js, Tautulli API v2.

**Spec:** `docs/superpowers/specs/2026-06-16-tautulli-page-v2-design.md`

---

## ⚠️ Environment constraint (read first)

No local PHP/Docker on this Windows box. Do **NOT** run `phpunit`, `docker`, `make`, `composer`, or `npm` — they will fail. TDD steps are **author the failing test → author the implementation → verification deferred to CI**. CI's `make check` (PHPUnit + `lint:twig` + `lint:yaml`) runs on push (final task) and is the source of truth. Use the **PowerShell tool** for git with absolute paths: `git -C C:\workspace\Prismarr ...`. Multi-line commit messages via single-quoted here-string `@' … '@` with the closing `'@` at column 0.

Commit trailer for every commit:
```
Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
```

---

## File Structure

**Delete:**
- `symfony/templates/tautulli/_recently_added.html.twig`

**Modify:**
- `symfony/src/Service/Media/TautulliClient.php` — remove `getRecentlyAdded`/`normalizeRecentlyAdded`; add `playsChart()` helper + `getPlaysByStreamType`/`getPlaysByHourOfDay`/`getPlaysByDayOfWeek`/`getStreamTypeByPlatform`; refactor `getPlaysByDate` onto the helper; drop "Total" series in `normalizePlaysByDate`.
- `symfony/src/Controller/TautulliController.php` — remove `apiRecentlyAdded` + revert docblock; add `mode` to `apiPlays`; add `apiActivityHour`/`apiActivityDow`/`apiClientsStreamType`.
- `symfony/templates/tautulli/_history_rows.html.twig` — render a responsive grid of compact cells.
- `symfony/templates/tautulli/_libraries.html.twig` — slim single-column list.
- `symfony/templates/tautulli/index.html.twig` — remove Recently Added; restructure History/Libraries; add plays mode toggle + activity + problem-clients cards; generalized `<script>`; CSS.
- `symfony/tests/Service/Media/TautulliClientTest.php` — remove recently-added tests; add Total-drop + chart fail-open tests.
- `symfony/tests/Controller/TautulliControllerTest.php` — remove recently-added smoke test; add the new-endpoint smoke tests.
- `symfony/translations/messages+intl-icu.en.yaml` / `…fr.yaml` — remove recently-added keys; add chart-section + mode-toggle keys.
- `CHANGELOG.md`, `docs/FORK-CHANGES.md`.

---

## Task 1: Remove Recently Added (full revert)

**Files:** delete `symfony/templates/tautulli/_recently_added.html.twig`; modify `TautulliController.php`, `TautulliClient.php`, `TautulliClientTest.php`, `TautulliControllerTest.php`, `tautulli/index.html.twig`, both yaml files.

- [ ] **Step 1: Delete the fragment template**

Delete `symfony/templates/tautulli/_recently_added.html.twig`.

- [ ] **Step 2: Remove the controller endpoint + revert docblock**

In `symfony/src/Controller/TautulliController.php`, delete the entire `apiRecentlyAdded()` method (its `/** … */` docblock + `#[Route('/api/recently-added', …)]` + method body). Then revert the class-level docblock command list back to (remove `get_recently_added`):

```php
     * The page also exposes read-only `get_metadata`, `get_history`,
     * `get_home_stats`, `get_plays_by_date`, and `get_libraries` — no
     * terminate_session, notifications or any other mutating Tautulli command.
```

- [ ] **Step 3: Remove the client method + normalizer**

In `symfony/src/Service/Media/TautulliClient.php`, delete `getRecentlyAdded()` and `normalizeRecentlyAdded()` (both methods, with their docblocks).

- [ ] **Step 4: Remove the client tests**

In `symfony/tests/Service/Media/TautulliClientTest.php`, delete `recentlyAddedFixture()`, `testNormalizeRecentlyAddedMapsFields()`, `testNormalizeRecentlyAddedStripsPrivateFields()`, and `testNormalizeRecentlyAddedEmpty()`.

- [ ] **Step 5: Remove the controller smoke test**

In `symfony/tests/Controller/TautulliControllerTest.php`, delete `testRecentlyAddedFragmentRendersEmptyStateWhenUnconfigured()` (and its docblock).

- [ ] **Step 6: Remove the page section + JS**

In `symfony/templates/tautulli/index.html.twig`:
- Delete the `{# 5. Recently Added #}` card block (lines ~83–89: the `<div class="card mb-3">` … `</div>`).
- Delete the `refreshRecentlyAdded` function:
```javascript
  // Recently Added — once.
  function refreshRecentlyAdded() {
    loadFragment('/tautulli/api/recently-added', frag('[data-tautulli-recently-added]'));
  }
```
- Delete the `refreshRecentlyAdded();` call in the Initial hydration block.

- [ ] **Step 7: Remove i18n keys (en + fr)**

In both `symfony/translations/messages+intl-icu.en.yaml` and `…fr.yaml`, under `tautulli:`: remove `recently_added` from the `section:` map, and remove the whole `recently_added:` block (`empty:` child).

- [ ] **Step 8: Commit**

```bash
git -C C:\workspace\Prismarr add -A
git -C C:\workspace\Prismarr commit -m @'
revert(tautulli): remove Recently Added section

The *arr apps already surface recently added; drop the section, its
endpoint, client method + normalizer, tests, and i18n keys.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
'@
```
(`git add -A` is safe here — the only changes in the tree are this task's, including the deleted file.)

---

## Task 2: Client — drop "Total" series + new chart methods (TDD)

**Files:** `symfony/src/Service/Media/TautulliClient.php`, `symfony/tests/Service/Media/TautulliClientTest.php`.

- [ ] **Step 1: Write the failing tests**

Append to `symfony/tests/Service/Media/TautulliClientTest.php` (after the existing plays tests, before the next fixture):

```php
    public function testNormalizePlaysByDateDropsTotalSeries(): void
    {
        $out = TautulliClient::normalizePlaysByDate([
            'categories' => ['2026-06-01', '2026-06-02'],
            'series'     => [
                ['name' => 'TV',     'data' => [3, 1]],
                ['name' => 'Total',  'data' => [4, 3]],
                ['name' => 'Movies', 'data' => [1, 2]],
            ],
        ]);
        // The aggregate "Total" series is dropped (case-insensitive); per-type stays.
        self::assertCount(2, $out['series']);
        self::assertSame(['TV', 'Movies'], array_map(static fn ($s) => $s['name'], $out['series']));
    }

    public function testChartMethodsFailOpenWhenUnconfigured(): void
    {
        $repo = $this->createMock(SettingRepository::class);
        $repo->method('getAll')->willReturn([]);
        $client = new TautulliClient(new ConfigService($repo), new NullLogger(), null);

        $neutral = ['categories' => [], 'series' => []];
        self::assertSame($neutral, $client->getPlaysByStreamType(30));
        self::assertSame($neutral, $client->getPlaysByHourOfDay(30));
        self::assertSame($neutral, $client->getPlaysByDayOfWeek(30));
        self::assertSame($neutral, $client->getStreamTypeByPlatform(30));
    }
```

- [ ] **Step 2: (Verification deferred to CI)**

These reference methods that don't exist yet and the Total-drop behavior — they fail until Step 3/4. CI verifies.

- [ ] **Step 3: Drop the "Total" series in `normalizePlaysByDate`**

In `TautulliClient::normalizePlaysByDate()`, inside the `foreach ($rawSeries as $s)` loop, right after the `if (!is_array($s)) { continue; }` guard, add:

```php
            $sname = self::str($s['name'] ?? null) ?? '';
            if (strtolower($sname) === 'total') {
                continue; // aggregate line — charts show the per-type breakdown only
            }
```

Then reuse `$sname` for the existing `'name' => …` mapping (replace `self::str($s['name'] ?? null) ?? ''` in the series push with `$sname`). The rest of the method is unchanged.

- [ ] **Step 4: Add the shared helper + four chart methods; refactor `getPlaysByDate`**

In `TautulliClient.php`, replace the existing `getPlaysByDate()` body with a delegation and add the helper + new methods. Place the new methods next to `getPlaysByDate`/`normalizePlaysByDate`:

```php
    /**
     * Plays-per-day series for the activity graph, ready for Chart.js.
     *
     * @return array{categories:list<string>, series:list<array{name:string,data:list<int>}>}
     */
    public function getPlaysByDate(int $days): array
    {
        return $this->playsChart('get_plays_by_date', $days);
    }

    /** Plays per day by transcode decision (Direct Play / Direct Stream / Transcode). */
    public function getPlaysByStreamType(int $days): array
    {
        return $this->playsChart('get_plays_by_stream_type', $days);
    }

    /** Plays aggregated by hour of day (0–23). */
    public function getPlaysByHourOfDay(int $days): array
    {
        return $this->playsChart('get_plays_by_hourofday', $days);
    }

    /** Plays aggregated by day of week. */
    public function getPlaysByDayOfWeek(int $days): array
    {
        return $this->playsChart('get_plays_by_dayofweek', $days);
    }

    /** Plays by top-10 platform, split by transcode decision (problem clients). */
    public function getStreamTypeByPlatform(int $days): array
    {
        return $this->playsChart('get_stream_type_by_top_10_platforms', $days);
    }

    /**
     * Shared fetch for the read-only Tautulli chart commands that all return the
     * `{categories, series}` envelope. Clamps the range and fails open to the
     * neutral shape (disabled/unconfigured/unreachable).
     *
     * @return array{categories:list<string>, series:list<array{name:string,data:list<int>}>}
     */
    private function playsChart(string $cmd, int $days): array
    {
        $this->ensureConfig();
        if (!$this->enabled || $this->baseUrl === '' || $this->apiKey === '') {
            return self::normalizePlaysByDate([]);
        }
        $resp = $this->request(['cmd' => $cmd, 'time_range' => (string) self::clampRange($days)]);
        if ($resp === null || $resp['ok'] !== true) {
            return self::normalizePlaysByDate([]);
        }
        return self::normalizePlaysByDate(is_array($resp['data']) ? $resp['data'] : []);
    }
```

(Delete the old `getPlaysByDate` body that inlined the `ensureConfig`/`request`/`normalize` logic — it is now in `playsChart`.)

- [ ] **Step 5: Commit**

```bash
git -C C:\workspace\Prismarr add symfony/src/Service/Media/TautulliClient.php symfony/tests/Service/Media/TautulliClientTest.php
git -C C:\workspace\Prismarr commit -m @'
feat(tautulli): add stream-type/hour/dow/platform chart feeds; drop Total

normalizePlaysByDate now drops any aggregate "Total" series. New
read-only chart commands share a playsChart() helper and the existing
normalizer; getPlaysByDate refactored onto it.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
'@
```

---

## Task 3: Controller — mode param + new chart endpoints (TDD)

**Files:** `symfony/src/Controller/TautulliController.php`, `symfony/tests/Controller/TautulliControllerTest.php`.

- [ ] **Step 1: Write the failing smoke tests**

Append to `symfony/tests/Controller/TautulliControllerTest.php` (before the final `}`):

```php
    /**
     * The new chart endpoints fail open to the neutral {categories:[],series:[]}
     * JSON when Tautulli is unconfigured, and the plays endpoint accepts the
     * stream-type mode without erroring.
     */
    public function testChartEndpointsReturnNeutralJsonWhenUnconfigured(): void
    {
        foreach ([
            '/tautulli/api/plays?range=30&mode=stream',
            '/tautulli/api/activity-hour?range=30',
            '/tautulli/api/activity-dow?range=30',
            '/tautulli/api/clients-stream-type?range=30',
        ] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            $content = $this->client->getResponse()->getContent();
            self::assertNotFalse($content);
            self::assertJson($content);
            /** @var array{categories: mixed, series: mixed} $data */
            $data = json_decode($content, true);
            self::assertSame([], $data['categories'], $url);
            self::assertSame([], $data['series'], $url);
        }
    }
```

- [ ] **Step 2: (Verification deferred to CI)**

The new routes don't exist yet — fails until Step 3. CI verifies.

- [ ] **Step 3: Extend `apiPlays` and add the three endpoints**

In `symfony/src/Controller/TautulliController.php`, replace the existing `apiPlays` method with the `mode`-aware version, and add the three new actions after it:

```php
    /** GET /tautulli/api/plays?range=30&mode=media|stream — plays series as JSON (Chart.js). */
    #[Route('/api/plays', name: 'api_plays', methods: ['GET'])]
    public function apiPlays(Request $request): JsonResponse
    {
        $range = (int) $request->query->get('range', 30);
        $mode  = $request->query->get('mode', 'media') === 'stream' ? 'stream' : 'media';
        try {
            $data = $mode === 'stream'
                ? $this->tautulli->getPlaysByStreamType($range)
                : $this->tautulli->getPlaysByDate($range);
            return $this->json($data);
        } catch (\Throwable) {
            return $this->json(['categories' => [], 'series' => []]);
        }
    }

    /** GET /tautulli/api/activity-hour?range=30 — plays by hour of day as JSON. */
    #[Route('/api/activity-hour', name: 'api_activity_hour', methods: ['GET'])]
    public function apiActivityHour(Request $request): JsonResponse
    {
        try {
            return $this->json($this->tautulli->getPlaysByHourOfDay((int) $request->query->get('range', 30)));
        } catch (\Throwable) {
            return $this->json(['categories' => [], 'series' => []]);
        }
    }

    /** GET /tautulli/api/activity-dow?range=30 — plays by day of week as JSON. */
    #[Route('/api/activity-dow', name: 'api_activity_dow', methods: ['GET'])]
    public function apiActivityDow(Request $request): JsonResponse
    {
        try {
            return $this->json($this->tautulli->getPlaysByDayOfWeek((int) $request->query->get('range', 30)));
        } catch (\Throwable) {
            return $this->json(['categories' => [], 'series' => []]);
        }
    }

    /** GET /tautulli/api/clients-stream-type?range=30 — plays by platform × stream type as JSON. */
    #[Route('/api/clients-stream-type', name: 'api_clients_stream_type', methods: ['GET'])]
    public function apiClientsStreamType(Request $request): JsonResponse
    {
        try {
            return $this->json($this->tautulli->getStreamTypeByPlatform((int) $request->query->get('range', 30)));
        } catch (\Throwable) {
            return $this->json(['categories' => [], 'series' => []]);
        }
    }
```

Also update the class docblock command list to include the new read-only commands (append after `get_libraries`): `get_plays_by_stream_type`, `get_plays_by_hourofday`, `get_plays_by_dayofweek`, `get_stream_type_by_top_10_platforms`.

- [ ] **Step 4: Commit**

```bash
git -C C:\workspace\Prismarr add symfony/src/Controller/TautulliController.php symfony/tests/Controller/TautulliControllerTest.php
git -C C:\workspace\Prismarr commit -m @'
feat(tautulli): add stream-type/activity/clients chart endpoints

apiPlays gains a media|stream mode; three new fail-open JSON endpoints
feed the hour-of-day, day-of-week, and platform x stream-type charts.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
'@
```

---

## Task 4: History grid + Libraries slim list (templates)

**Files:** `symfony/templates/tautulli/_history_rows.html.twig`, `symfony/templates/tautulli/_libraries.html.twig`, `symfony/templates/tautulli/index.html.twig`.

> Note for reviewers: after this task, the page JS still counts history items with the old `plex-session` regex and the chart sections aren't added yet — both are finalized in Task 5. That cross-task gap is intentional (no deploy until the final task).

- [ ] **Step 1: History grid cells**

Replace the entire contents of `symfony/templates/tautulli/_history_rows.html.twig` with:

```twig
{# History grid cells. `plex_history` is a list of normalized rows. Returns
   nothing when empty so the "Load more" JS can detect the end of the list.
   Each cell is a grid column carrying the info-modal trigger. #}
{% import '_icons.html.twig' as ico %}
{% for h in plex_history %}
  <div class="col-6 col-md-4">
    <div class="plex-hist-cell {% if h.ratingKey %}plex-clickable{% endif %}"
         {% if h.ratingKey %}data-plex-rating-key="{{ h.ratingKey }}" role="button" tabindex="0"{% endif %}>
      <div class="plex-poster plex-poster-sm">
        {{ ico.icon(h.mediaType == 'movie' ? 'movie' : 'device-tv', '', 18) }}
        {% if h.posterPath %}<img class="plex-poster-img" src="{{ path('app_tautulli_api_image', {img: h.posterPath}) }}" alt="" loading="lazy">{% endif %}
      </div>
      <div class="plex-hist-cell-main">
        <div class="plex-hist-cell-title text-truncate">
          {% if h.mediaType == 'episode' and h.grandparentTitle %}{{ h.grandparentTitle }}{% else %}{{ h.title }}{% endif %}{% if h.year %} <span class="plex-session-year">({{ h.year }})</span>{% endif %}
        </div>
        <div class="plex-hist-cell-meta text-secondary text-truncate">
          {{ [h.userDisplayName, h.watchedAt|relative_date]|filter(v => v is not null)|join(' · ') }}
        </div>
      </div>
    </div>
  </div>
{% endfor %}
```

- [ ] **Step 2: Libraries slim list**

Replace the entire contents of `symfony/templates/tautulli/_libraries.html.twig` with:

```twig
{# Library list (slim single column). `libraries` = list of {name, type, count, childCount}. #}
{% import '_icons.html.twig' as ico %}
{% if libraries is empty %}
  <div class="text-center text-secondary py-4">{{ 'tautulli.libraries.empty'|trans }}</div>
{% else %}
<div class="list-group list-group-flush">
  {% for lib in libraries %}
    <div class="list-group-item d-flex align-items-center gap-2 px-0">
      {{ ico.icon(lib.type == 'movie' ? 'movie' : (lib.type == 'show' ? 'device-tv' : 'book'), 'text-secondary', 20) }}
      <div class="fw-bold text-truncate">{{ lib.name }}</div>
      <div class="text-secondary small ms-auto text-nowrap">
        {{ 'tautulli.libraries.items'|trans({count: lib.count}) }}{% if lib.childCount %} · {{ 'tautulli.libraries.episodes'|trans({count: lib.childCount}) }}{% endif %}
      </div>
    </div>
  {% endfor %}
</div>
{% endif %}
```

- [ ] **Step 3: Restructure History + Libraries in the page**

In `symfony/templates/tautulli/index.html.twig`, replace the entire `{# 4. History + Libraries #}` block (the `<div class="row row-cards mb-3">` … its matching close, currently lines ~58–81) with two separate full-width cards:

```twig
    {# 6. History — full-width dense grid #}
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">{{ 'tautulli.section.history'|trans }}</h3></div>
      <div class="card-body">
        <div class="row row-cards g-2" data-tautulli-history>
          <div class="col-12 text-center text-secondary py-4"><span class="spinner-border"></span></div>
        </div>
        <div class="text-center mt-3">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-tautulli-history-more style="display:none;">{{ 'tautulli.history.load_more'|trans }}</button>
        </div>
      </div>
    </div>

    {# 7. Libraries — slim single-column list #}
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">{{ 'tautulli.section.libraries'|trans }}</h3></div>
      <div class="card-body" data-tautulli-libraries>
        <div class="text-center text-secondary py-4"><span class="spinner-border"></span></div>
      </div>
    </div>
```

- [ ] **Step 4: Add history-cell CSS**

In `index.html.twig`'s `<style>` block, add these rules after the existing `.plex-poster-sm` rule:

```css
  .plex-hist-cell { display: flex; gap: .5rem; align-items: center; min-width: 0; }
  .plex-hist-cell-main { min-width: 0; }
  .plex-hist-cell-title { font-size: .8rem; }
  .plex-hist-cell-meta { font-size: .72rem; }
```

- [ ] **Step 5: Commit**

```bash
git -C C:\workspace\Prismarr add symfony/templates/tautulli/_history_rows.html.twig symfony/templates/tautulli/_libraries.html.twig symfony/templates/tautulli/index.html.twig
git -C C:\workspace\Prismarr commit -m @'
feat(tautulli): dense History grid + slim Libraries list

History renders as a responsive multi-column grid (poster + title +
user/when, no progress bar); Libraries becomes a slim single-column
list. Both are now full-width sections.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
'@
```

---

## Task 5: Plays toggle + activity/clients charts + JS + i18n

**Files:** `symfony/templates/tautulli/index.html.twig`, `symfony/translations/messages+intl-icu.en.yaml`, `…fr.yaml`.

- [ ] **Step 1: Add the Media⇄Stream toggle to the Plays card**

In `index.html.twig`, replace the existing `{# 3. Plays over time #}` card with:

```twig
    {# 3. Plays over time #}
    <div class="card mb-3">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title">{{ 'tautulli.section.plays'|trans }}</h3>
        <div class="btn-group btn-group-sm plex-range" role="group" data-tautulli-plays-mode>
          <button type="button" class="btn btn-sm active" data-mode="media" aria-pressed="true">{{ 'tautulli.plays.mode.media'|trans }}</button>
          <button type="button" class="btn btn-sm" data-mode="stream" aria-pressed="false">{{ 'tautulli.plays.mode.stream'|trans }}</button>
        </div>
      </div>
      <div class="card-body">
        <div id="tautulli-plays-wrap" class="tautulli-chart-wrap"><canvas id="tautulli-plays"></canvas></div>
        <div data-tautulli-plays-empty class="text-center text-secondary py-4" style="display:none;">{{ 'tautulli.plays.empty'|trans }}</div>
      </div>
    </div>
```

- [ ] **Step 2: Insert the Activity + Problem-clients cards**

Immediately after the Plays card (Step 1) and before the `{# 6. History #}` card, insert:

```twig
    {# 4. Activity patterns #}
    <div class="row row-cards mb-3">
      <div class="col-lg-6">
        <div class="card h-100">
          <div class="card-header"><h3 class="card-title">{{ 'tautulli.section.activity_hour'|trans }}</h3></div>
          <div class="card-body">
            <div id="tautulli-activity-hour-wrap" class="tautulli-chart-wrap"><canvas id="tautulli-activity-hour"></canvas></div>
            <div data-tautulli-activity-hour-empty class="text-center text-secondary py-4" style="display:none;">{{ 'tautulli.plays.empty'|trans }}</div>
          </div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="card h-100">
          <div class="card-header"><h3 class="card-title">{{ 'tautulli.section.activity_dow'|trans }}</h3></div>
          <div class="card-body">
            <div id="tautulli-activity-dow-wrap" class="tautulli-chart-wrap"><canvas id="tautulli-activity-dow"></canvas></div>
            <div data-tautulli-activity-dow-empty class="text-center text-secondary py-4" style="display:none;">{{ 'tautulli.plays.empty'|trans }}</div>
          </div>
        </div>
      </div>
    </div>

    {# 5. Problem clients — platform × stream type #}
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">{{ 'tautulli.section.clients'|trans }}</h3></div>
      <div class="card-body">
        <div id="tautulli-clients-wrap" class="tautulli-chart-wrap"><canvas id="tautulli-clients-stream-type"></canvas></div>
        <div data-tautulli-clients-empty class="text-center text-secondary py-4" style="display:none;">{{ 'tautulli.plays.empty'|trans }}</div>
      </div>
    </div>
```

- [ ] **Step 3: Add chart-wrap CSS**

In the `<style>` block, replace the existing `#tautulli-plays-wrap { position: relative; height: 240px; }` rule with a shared class:

```css
  .tautulli-chart-wrap { position: relative; height: 240px; }
```

- [ ] **Step 4: Replace the whole page `<script>` IIFE**

In `index.html.twig`, replace the entire `(function () { … })();` IIFE inside `{% block javascripts %}` with this final version (generalized `drawStackedBar`, all four chart refreshers, the mode toggle, range refreshes all charts, history cell-count regex updated to `plex-hist-cell`):

```javascript
(function () {
  var HISTORY_PAGE = 25;
  var range = 30;
  var playsMode = 'media';
  var historyStart = 0;
  var charts = {};

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
        var rowCount = (trimmed.match(/class="[^"]*plex-hist-cell/g) || []).length;
        if (!append && !rowCount) box.innerHTML = '<div class="col-12 text-center text-secondary py-4">—</div>';
        historyStart += HISTORY_PAGE;
        if (more) more.style.display = rowCount >= HISTORY_PAGE ? '' : 'none';
      })
      .catch(function () {
        if (!append) {
          var box = frag('[data-tautulli-history]');
          var more = frag('[data-tautulli-history-more]');
          if (box) box.innerHTML = '<div class="col-12 text-center text-secondary py-4">—</div>';
          if (more) more.style.display = 'none';
        }
      });
  }

  // Charts — fetch JSON, (re)draw a stacked bar into the given canvas.
  var SERIES_COLORS = {
    'TV': '#22c55e', 'Movies': '#6366f1', 'Music': '#f59e0b',
    'Direct Play': '#22c55e', 'Direct Stream': '#0ea5e9', 'Transcode': '#f59e0b'
  };
  function drawStackedBar(opts) {
    fetch(opts.url, { headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        var wrap = frag(opts.wrap);
        var empty = frag(opts.empty);
        var hasData = d && d.series && d.series.length && d.categories && d.categories.length;
        if (wrap) wrap.style.display = hasData ? '' : 'none';
        if (empty) empty.style.display = hasData ? 'none' : '';
        if (!hasData) { if (charts[opts.id]) { charts[opts.id].destroy(); charts[opts.id] = null; } return; }
        var datasets = d.series.map(function (s) {
          var c = SERIES_COLORS[s.name] || '#94a3b8';
          return { label: s.name, data: s.data, backgroundColor: c, borderColor: c, borderRadius: 3, borderSkipped: false };
        });
        if (charts[opts.id]) charts[opts.id].destroy();
        charts[opts.id] = new Chart(frag('#' + opts.id), {
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

  function refreshPlays() {
    drawStackedBar({ id: 'tautulli-plays', url: '/tautulli/api/plays?range=' + range + '&mode=' + playsMode, wrap: '#tautulli-plays-wrap', empty: '[data-tautulli-plays-empty]' });
  }
  function refreshActivityHour() {
    drawStackedBar({ id: 'tautulli-activity-hour', url: '/tautulli/api/activity-hour?range=' + range, wrap: '#tautulli-activity-hour-wrap', empty: '[data-tautulli-activity-hour-empty]' });
  }
  function refreshActivityDow() {
    drawStackedBar({ id: 'tautulli-activity-dow', url: '/tautulli/api/activity-dow?range=' + range, wrap: '#tautulli-activity-dow-wrap', empty: '[data-tautulli-activity-dow-empty]' });
  }
  function refreshClients() {
    drawStackedBar({ id: 'tautulli-clients-stream-type', url: '/tautulli/api/clients-stream-type?range=' + range, wrap: '#tautulli-clients-wrap', empty: '[data-tautulli-clients-empty]' });
  }
  function refreshCharts() { refreshPlays(); refreshActivityHour(); refreshActivityDow(); refreshClients(); }

  // Range toggle.
  var rangeBar = frag('[data-tautulli-range]');
  if (rangeBar) {
    rangeBar.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-range]');
      if (!btn) return;
      range = parseInt(btn.getAttribute('data-range'), 10) || 30;
      rangeBar.querySelectorAll('[data-range]').forEach(function (b) {
        var on = b === btn;
        b.classList.toggle('active', on);
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      refreshStats();
      refreshCharts();
    });
  }

  // Plays mode toggle (Media Type / Stream Type).
  var modeBar = frag('[data-tautulli-plays-mode]');
  if (modeBar) {
    modeBar.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-mode]');
      if (!btn) return;
      playsMode = btn.getAttribute('data-mode') === 'stream' ? 'stream' : 'media';
      modeBar.querySelectorAll('[data-mode]').forEach(function (b) {
        var on = b === btn;
        b.classList.toggle('active', on);
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      refreshPlays();
    });
  }

  // Initial hydration.
  refreshStats();
  refreshCharts();
  refreshLibraries();
  loadHistory(false);
  var moreBtn = frag('[data-tautulli-history-more]');
  if (moreBtn) moreBtn.addEventListener('click', function () { loadHistory(true); });

  // Now-playing poll (singleton across Turbo re-exec).
  if (window._tautulliNowTimer) clearInterval(window._tautulliNowTimer);
  window._tautulliNowTimer = setInterval(refreshNow, 10000);
  if (!window._tautulliNowCleanupBound) {
    window._tautulliNowCleanupBound = true;
    document.addEventListener('turbo:before-render', function () {
      if (window._tautulliNowTimer) { clearInterval(window._tautulliNowTimer); window._tautulliNowTimer = null; }
    });
  }
})();
```

- [ ] **Step 5: Add i18n keys (en)**

In `symfony/translations/messages+intl-icu.en.yaml`, under `tautulli:`:
- In the `section:` map add: `activity_hour: Plays by hour of day`, `activity_dow: Plays by day of week`, `clients: Plays by platform & stream type`.
- Under the `plays:` block (which already has `empty:`) add a `mode:` child:
```yaml
  plays:
    empty: No play data for this period
    mode:
      media: Media type
      stream: Stream type
```

- [ ] **Step 6: Add i18n keys (fr)**

In `symfony/translations/messages+intl-icu.fr.yaml`, mirror under `tautulli:`:
- `section:` → `activity_hour: Lectures par heure`, `activity_dow: Lectures par jour de semaine`, `clients: Lectures par plateforme et type de flux`.
- `plays.mode.media: Type de média`, `plays.mode.stream: Type de flux`.

(Match the existing fr `tautulli:` nesting and 2-space indentation.)

- [ ] **Step 7: Commit**

```bash
git -C C:\workspace\Prismarr add symfony/templates/tautulli/index.html.twig symfony/translations/messages+intl-icu.en.yaml symfony/translations/messages+intl-icu.fr.yaml
git -C C:\workspace\Prismarr commit -m @'
feat(tautulli): Media/Stream plays toggle + activity & problem-client graphs

Generalize the chart JS into drawStackedBar; add a Media Type/Stream
Type toggle on the plays chart and new hour-of-day, day-of-week, and
platform x stream-type charts, all driven by the range toggle.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
'@
```

---

## Task 6: Docs, CHANGELOG, and CI verification

**Files:** `CHANGELOG.md`, `docs/FORK-CHANGES.md`.

- [ ] **Step 1: Update CHANGELOG**

In `CHANGELOG.md` under `## [Unreleased]`: the prior "Tautulli Plex Activity page enhancements" bullet (added earlier this session) mentions Recently Added, which never shipped in a release. Edit that bullet to **drop the Recently Added mention** and describe v2 instead. Replace its text with:

```markdown
- **Tautulli Plex Activity page enhancements.** The activity page gained a stream-summary strip above Now Playing (session count, Direct Play / Direct Stream / Transcode breakdown, total/LAN/WAN bandwidth), richer live stream cards (HDR/SDR badge and, on transcoding streams, the source→target codec transition), a denser full-width History grid, a slim Libraries list, and a set of graphs: a Media Type ⇄ Stream Type toggle on the plays-over-time chart, plays by hour of day and day of week, and a platform × stream-type "problem clients" chart. All graph data comes from read-only Tautulli commands and is sanitized to the same `{categories, series}` shape; charts omit the aggregate "Total" series.
```

- [ ] **Step 2: Update docs/FORK-CHANGES.md**

In `docs/FORK-CHANGES.md`, in the "Activity-page enhancements (2026-06-16)" sub-block (added earlier this session), remove the Recently Added bullet and add bullets for: the dense History grid + slim Libraries, and the new graphs (Media/Stream toggle, hour-of-day + day-of-week, platform × stream-type problem clients; Total series dropped). Keep wording consistent with the file's style.

- [ ] **Step 3: Commit**

```bash
git -C C:\workspace\Prismarr add CHANGELOG.md docs/FORK-CHANGES.md
git -C C:\workspace\Prismarr commit -m @'
docs: changelog + fork-changes for Tautulli page v2

Drop the un-shipped Recently Added note; record the declutter +
graphs work.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
'@
```

- [ ] **Step 4: Push and verify CI (with user go-ahead)**

Pushing is outward-facing (CI + public GHCR image rebuild). With the user's go-ahead:

```bash
git -C C:\workspace\Prismarr push origin main
```

Then watch CI's `make check` (PHPUnit + `lint:twig` + `lint:yaml`) — **the verification gate for all TDD tests in Tasks 2 and 3 and the lint of the new templates/yaml**. Fix forward until green. Confirm the GHCR `:latest` image rebuilds.

- [ ] **Step 5: Manual verification on Unraid (after image rebuild)**

After Force Update, on **Tautulli → Plex Activity**:
- Recently Added section is gone.
- History is a dense multi-column grid (poster + title + user·when, no progress bar); "Load more" still paginates and stops.
- Libraries is a slim single-column list.
- Plays chart toggles Media Type ⇄ Stream Type and shows no "Total" series.
- Hour-of-day, day-of-week, and platform × stream-type charts draw and respond to the 7/30/90-day toggle.
- Stop Tautulli → every chart shows its empty state, page intact (no 500).

---

## Self-Review Notes

- **Spec coverage:** Component 1 → Task 1; Component 4 (data) → Task 2; Component 5 (endpoints) → Task 3; Components 2+3 (History/Libraries) → Task 4; Component 6 (templates/JS) → Task 5; Component 7 (i18n/docs/test/CI) → Tasks 2/3 (tests), 5 (i18n), 6 (docs/CI). All mapped.
- **Type/contract consistency:** every chart method returns `{categories, series}` via `normalizePlaysByDate`; controllers fail open to `{categories:[],series:[]}`; the JS `drawStackedBar` consumes that shape for all four canvases; series names `Direct Play`/`Direct Stream`/`Transcode` match the `SERIES_COLORS` keys; the history grid cell class `plex-hist-cell` matches the `loadHistory` count regex.
- **Cross-task ordering:** Task 4 must precede Task 5 (Task 5 inserts the chart cards before the `{# 6. History #}` anchor Task 4 creates, and finalizes the script). Tasks 1→2→3→4→5→6 in order.
- **No placeholders:** every code step contains complete, copy-ready content.
