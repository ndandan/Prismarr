# Upstream Issue Set (#54, #35, #47, #71) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix four upstream-reported gaps in one working set: dates honor the Display preference everywhere (#54), interactive-search release titles link to their tracker page (#35), the SABnzbd/NZBGet downloads page shows recent history inline (#47), and Prowlarr search results get a one-click Grab action (#71).

**Architecture:** Tasks 1–3 (#35, #47, #71) touch disjoint surfaces and run in parallel isolated git worktrees, each on its own branch, merged sequentially into `feat/upstream-set-54-35-47-71` after review. Task 4 (#54) is a call-site sweep that runs **last** on the merged result so it also normalizes any dates the other tasks introduce. All display formatting flows through the existing, already-tested `DisplayPreferencesService` (`fr`/`us`/`iso` dates, `12h`/`24h` time, user timezone) — no new preference storage is built.

**Tech Stack:** Symfony 8 (PHP 8.4), Twig, vanilla inline JS (Turbo-driven pages), PHPUnit via Docker (no local PHP), Tabler/Bootstrap under `window.tabler`.

## Global Constraints

- **No local PHP.** Tests run in Docker. Unit tests (plain `TestCase`):
  `MSYS_NO_PATHCONV=1 docker run --rm --entrypoint php -e APP_ENV=test -v "<abs-path>/symfony:/var/www/html" -w /var/www/html prismarr-prismarr:latest vendor/bin/phpunit --filter <Name>`
  Functional tests (`AbstractWebTestCase`) need a booted container — see per-task steps.
- **Worktree trap:** a fresh worktree lacks `symfony/vendor` and `symfony/assets/vendor` (gitignored). When running tests from a worktree, overlay both from the main checkout as extra `-v` mounts (see task steps). Never run `composer install` into the worktree.
- **Unique container names** per task: `prismarr-t35`, `prismarr-t47`, `prismarr-t71`, `prismarr-t54` — parallel tasks must not collide. `docker rm -f <name>` when done.
- **i18n:** every new user-facing string gets a key in BOTH `symfony/translations/messages+intl-icu.en.yaml` AND `.fr.yaml`. CI does NOT render most service templates (route guard 302s unconfigured services), so a missing key will NOT fail CI — grep-verify both files before committing.
- **JS:** no `window.bootstrap` global — imperative Bootstrap classes resolve via `window.tabler` (declarative `data-bs-*` attributes are fine). New code inside existing inline `<script>` blocks inherits the page's CSP nonce; do not add new external script files.
- **Fork parity:** do not delete or restructure upstream-origin code beyond the minimal change; these commits become per-issue upstream PRs.
- **XSS:** anything interpolated into JS-built HTML strings goes through the template's existing `esc()` helper — including URLs (`href`).
- **External links:** `target="_blank" rel="noopener"` (repo convention).
- **Commits:** conventional style (`feat:`/`fix:`), one logical change per commit, ending with:
  `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`

---

### Task 1 (#35): infoUrl tracker links in Radarr/Sonarr interactive search

**Branch:** `set54/35-infourl` · **Model:** Sonnet · **Worktree:** yes

**Files:**
- Modify: `symfony/src/Controller/MediaController.php` (three `array_map` blocks: `filmReleases` ~396–413, `episodeReleases` ~1653–1675, `seasonReleases` ~1800–1820)
- Modify: `symfony/templates/media/films.html.twig` (`renderReleases`, title cell ~2454–2457)
- Modify: `symfony/templates/media/series.html.twig` (`renderEpReleases`, title cell ~3740–3744)
- Test: `symfony/tests/Controller/MediaReleasesSearchTest.php`

**Interfaces:**
- Consumes: `RadarrClient::getReleasesForMovie()` / `SonarrClient::getEpisodeReleases()` / `getSeasonReleases()` — raw passthrough, `infoUrl` already present in `$r`.
- Produces: each release JSON row gains `infoUrl: string|null`. Frontend contract: `r.infoUrl`.

- [ ] **Step 1: Write the failing test.** In `MediaReleasesSearchTest.php`, following the existing mock-construction pattern in that file, add a test per endpoint that stubs the client method to return one release array containing `'infoUrl' => 'https://tracker.example/torrent/1'` (plus minimal fields the mapper reads: `guid`, `indexerId`, `title`), calls the controller method, decodes the JSON, and asserts `$rows[0]['infoUrl'] === 'https://tracker.example/torrent/1'`. Also assert a release *without* the key maps to `null`.
- [ ] **Step 2: Run to verify failure.** `--filter MediaReleasesSearchTest` via the unit-test docker command (this test builds the controller by hand — no kernel). Expected: FAIL, `infoUrl` key missing.
- [ ] **Step 3: Implement.** Add `'infoUrl' => $r['infoUrl'] ?? null,` to all three `array_map` field lists in `MediaController.php`.
- [ ] **Step 4: Run to verify pass.**
- [ ] **Step 5: Frontend.** In both title-cell builders, when `r.infoUrl` is truthy wrap the escaped title:
  `'<a href="' + esc(r.infoUrl) + '" target="_blank" rel="noopener" class="text-reset">' + esc(r.title) + '</a>'`
  else keep the current plain `esc(r.title)`. Keep the rejection ✗ span outside the link. Verify the release rows have no row-level click handler that the link would shadow (grab is a separate button; if a row handler exists, add `event.stopPropagation()` guidance accordingly — check first, don't assume).
- [ ] **Step 6: Lint + commit.** `lint:twig` both templates inside the container (needs `-e APP_SECRET=test`). Commit: `feat(media): link interactive-search release titles to their tracker page (infoUrl) — upstream #35`.

---

### Task 2 (#47): SABnzbd/NZBGet — recent history inline on the downloads page

**Branch:** `set54/47-usenet-history` · **Model:** Opus · **Worktree:** yes

**Files:**
- Modify: `symfony/src/Controller/UsenetController.php` (`index()` lines 48–107)
- Modify: `symfony/src/Service/Media/Usenet/SabnzbdClient.php` (`normalizeHistorySlot()` 265–284)
- Modify: `symfony/src/Service/Media/Usenet/NzbgetClient.php` (history normalization — map its completion timestamp field if the API provides one; read the file first)
- Modify: `symfony/src/Service/Media/Usenet/UsenetDownload.php` (new nullable `completedAt` epoch field)
- Create: `symfony/templates/usenet/_history_rows.html.twig` (extracted row partial)
- Modify: `symfony/templates/usenet/history.html.twig` (use the partial; unchanged behavior)
- Modify: `symfony/templates/usenet/index.html.twig` (new "Recent history" section below the queue container at line 272)
- Modify: `symfony/translations/messages+intl-icu.en.yaml` + `.fr.yaml` (`usenet.history.recent_title`, `usenet.history.view_all` — check existing keys first; reuse `usenet.history.*` where possible)
- Test: `symfony/tests/Controller/UsenetControllerTest.php`, `symfony/tests/Service/Media/Usenet/SabnzbdClientTest.php`

**Interfaces:**
- Consumes: `UsenetClientInterface::getHistoryPage(int $offset, int $limit): array{items: UsenetDownload[], total: int}` (both clients implement it).
- Produces: `UsenetDownload->completedAt: ?int` (unix epoch, null when unknown); `index.html.twig` receives `recent_history: UsenetDownload[]` and `history_total: int`; partial `_history_rows.html.twig` takes `items` (and optional `compact: bool`).

**Design:** server-rendered, no polling for history (queue keeps its existing JS poller). `index()` fetches `getHistoryPage(0, 15)` inside a try/catch — on client error pass `recent_history: []` and render nothing extra (the page already shows its unreachable banner). Age pill renders `item.completedAt|relative_date` when `completedAt` is set, `—` otherwise. Do NOT introduce a `sabnzbd.*` i18n prefix — everything stays under `usenet.*` because NZBGet shares the stack.

- [ ] **Step 1: Failing unit test.** In `SabnzbdClientTest.php` (ReflectionMethod `call()` pattern already in the file): `normalizeHistorySlot(['nzo_id'=>'x','name'=>'n','status'=>'Completed','bytes'=>100,'completed'=>1755800000])` → assert `->completedAt === 1755800000`; absent `completed` key → `null`.
- [ ] **Step 2: Run to verify failure** (unit-test docker command, `--filter SabnzbdClientTest`).
- [ ] **Step 3: Implement the field.** Add `completedAt` to `UsenetDownload` (follow the class's existing constructor/property style exactly), map `(int)($slot['completed'] ?? 0) ?: null` in `SabnzbdClient::normalizeHistorySlot()`. Read `NzbgetClient` and map its equivalent (NZBGet history entries carry `HistoryTime` — verify the actual key used by this codebase's raw payload before mapping; if absent, leave `null`).
- [ ] **Step 4: Run to verify pass.**
- [ ] **Step 5: Extract the partial.** Move the `{% for item in items %}` row loop from `history.html.twig:45–64` into `_history_rows.html.twig` verbatim (keep the `.uh-row` classes so the existing CSS in history.html.twig applies — move that CSS block into the partial or into index's stylesheet block as appropriate so BOTH pages get it; prefer moving the `.uh-*` CSS into the partial guarded by a `{% block %}`-free plain `<style>` only if history.html.twig's structure requires it — otherwise duplicate-free include). Add the age pill: `{% if item.completedAt %}<span class="...">{{ item.completedAt|relative_date }}</span>{% endif %}`.
- [ ] **Step 6: Wire the controller.** In `index()`, after existing data assembly: try/catch `$hist = $this->client($client)->getHistoryPage(0, 15)`, pass `recent_history` + `history_total` to the template (empty array + 0 on failure).
- [ ] **Step 7: Render the section.** In `index.html.twig` below the queue container (~line 272): a section with heading (`usenet.history.recent_title`, e.g. "Recent history"), the included partial, and a "view all" link to `app_usenet_history` reusing the existing button pattern at 210–213. Render the section only when `recent_history is not empty`. Match the page's existing card/section markup idiom.
- [ ] **Step 8: Failing functional test.** In `UsenetControllerTest.php` using the existing `configureSabnzbd()` helper: stub `getHistoryPage` to return two items (one with `completedAt`), GET `/usenet/sabnzbd`, assert both names render and the history heading appears; second test: `getHistoryPage` throws → page still 200, no history heading.
- [ ] **Step 9: Run functional tests.** Booted-container recipe with vendor overlays (worktree!):
  ```
  MSYS_NO_PATHCONV=1 docker run -d --name prismarr-t47 -e APP_ENV=test -e APP_DEBUG=1 -e APP_SECRET=testsecret -e MERCURE_JWT_SECRET=testtesttesttesttesttesttesttesttest -v "<worktree>/symfony:/var/www/html" -v "C:/workspace/Prismarr/symfony/vendor:/var/www/html/vendor" -v "C:/workspace/Prismarr/symfony/assets/vendor:/var/www/html/assets/vendor" prismarr-prismarr:latest
  docker exec -e APP_ENV=test prismarr-t47 vendor/bin/phpunit --filter 'UsenetControllerTest|SabnzbdClientTest'
  docker rm -f prismarr-t47
  ```
- [ ] **Step 10: i18n + lint + commit.** Add EN+FR keys, grep-verify both files, `lint:twig` the three templates. Commits: `feat(usenet): map history completion time (completedAt) end-to-end` then `feat(usenet): show recent history inline on the downloads page — upstream #47`.

---

### Task 3 (#71): Prowlarr search — Grab action (+ tracker link)

**Branch:** `set54/71-prowlarr-grab` · **Model:** Sonnet · **Worktree:** yes

**Files:**
- Modify: `symfony/src/Service/Media/ProwlarrClient.php` (new `grab()` after `search()` ~line 229)
- Modify: `symfony/src/Controller/ProwlarrController.php` (new POST route)
- Modify: `symfony/templates/prowlarr/index.html.twig` (`doSearch()` rows 797–821 + delegated handler)
- Modify: `symfony/translations/messages+intl-icu.en.yaml` + `.fr.yaml`
- Test: create `symfony/tests/Controller/ProwlarrGrabTest.php`

**Interfaces:**
- Consumes: `search()` payload already carries `guid` + `indexerId` per row; `requestWithError()` returns `array{ok: bool, data?: array, error?: string, code: int}`; `window._prismarrToast(msg, type)`.
- Produces: `ProwlarrClient::grab(string $guid, int $indexerId): array` (requestWithError shape); route `prowlarr_grab` = `POST /prowlarr/grab` accepting JSON `{guid, indexerId}`, returning the requestWithError array (400 `{ok:false,error:...}` on missing/invalid input).

- [ ] **Step 1: Failing controller test.** New `ProwlarrGrabTest.php` modeled on `MediaReleasesSearchTest.php`'s hand-built-controller style (mock `ProwlarrClient`, mocked container so `json()` works): (a) valid `{guid:'g', indexerId:3}` → asserts client `grab('g', 3)` called once, returns its `{ok:true,...}` verbatim; (b) missing guid → 400, `{ok:false}`, client never called; (c) `indexerId: 0` → 400. (If `ProwlarrController`'s constructor makes hand-construction impractical, fall back to a `WebTestCase` following `UsenetControllerTest`'s config-seeding pattern with a `prowlarr_url` setting — read the route guard rules first.)
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Client method.**
  ```php
  /** Grab a search result via the indexer's configured download client. */
  public function grab(string $guid, int $indexerId): array
  {
      return $this->requestWithError('POST', '/api/v1/search', ['guid' => $guid, 'indexerId' => $indexerId]);
  }
  ```
- [ ] **Step 4: Controller route.**
  ```php
  #[Route('/grab', name: 'grab', methods: ['POST'])]
  public function grab(Request $request): JsonResponse
  {
      $data = $request->toArray();
      $guid = (string) ($data['guid'] ?? '');
      $indexerId = (int) ($data['indexerId'] ?? 0);
      if ($guid === '' || $indexerId <= 0) {
          return $this->json(['ok' => false, 'error' => 'invalid_request'], 400);
      }
      return $this->json($this->prowlarr->grab($guid, $indexerId));
  }
  ```
- [ ] **Step 5: Run to verify pass.**
- [ ] **Step 6: UI.** In `doSearch()`'s row builder: add an Actions column — a grab button carrying `data-guid` + `data-indexer-id` (both esc()'d), styled like the page's existing small buttons; wrap the title in the infoUrl link when `r.infoUrl` is truthy (`target="_blank" rel="noopener"`). Add ONE delegated click listener on the results container (rows are rebuilt per search — do not bind per-row): on grab click, disable button, `fetch('/prowlarr/grab', {method:'POST', headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'}, body: JSON.stringify({guid, indexerId})})` → `_prismarrToast` success (`prowlarr.search.grab_sent`) or error with server `error` string (`prowlarr.search.grab_failed_tpl` with `__ERROR__` replace, mirroring `qbtAction`'s `tpl()` usage); re-enable on failure, mark ✓ disabled on success. Header cell for the new column in the same string-built table head. All new user-visible strings via the page's existing JS i18n dict pattern (find how `_I18N` is populated in this template and extend it).
- [ ] **Step 7: i18n.** `prowlarr.search.grab` ("Grab"), `grab_sent` ("Sent to download client"), `grab_failed_tpl` ("Grab failed: __ERROR__") in EN + FR (`Envoyer au client de téléchargement`, etc.). Grep-verify both files.
- [ ] **Step 8: Lint + commit.** `lint:twig`. Commit: `feat(prowlarr): grab search results to the download client + tracker links — upstream #71 #35`.

---

### Task 4 (#54): date/time display-preference sweep

**Branch:** work directly on `feat/upstream-set-54-35-47-71` (runs AFTER tasks 1–3 merge) · **Model:** Opus · **Worktree:** no

**Files:**
- Modify: `symfony/src/Service/DisplayPreferencesService.php` (`formatTime`/`formatDateTime` gain `bool $withSeconds = false`)
- Modify: `symfony/src/Twig/DisplayPreferencesExtension.php` (filters pass the flag through)
- Modify: ~25 templates per the scout inventory (exact list in steps)
- Modify: `symfony/templates/base.html.twig` (JS date-format globals next to `_prismarrToast` ~line 2527)
- Modify: 6 templates' inline JS formatters
- Test: `symfony/tests/Service/DisplayPreferencesServiceTest.php`

**Interfaces:**
- Consumes: existing `|prismarr_date` / `|prismarr_time` / `|prismarr_datetime` filters (accept `DateTimeInterface|string|int`), `display_pref('date_format'|'time_format'|'timezone')`.
- Produces: `formatTime(?DateTimeInterface $dt, bool $withSeconds = false)`, `formatDateTime(?DateTimeInterface $dt, bool $withSeconds = false)`; Twig usage `|prismarr_time(true)`, `|prismarr_datetime(true)`; JS globals `window._prismarrDatePrefs = {date:'fr'|'us'|'iso', time:'12h'|'24h'}` and `window._prismarrFmtDate(d)`, `_prismarrFmtTime(d)`, `_prismarrFmtDateTime(d)` (accept `Date`; browser timezone).

**Commit A — seconds support (TDD):**
- [ ] Step A1: failing tests in `DisplayPreferencesServiceTest.php`: `formatTime($dt, true)` → `14:30:07` (24h) / `2:30:07 PM` (12h); `formatDateTime($dt, true)` includes seconds. Run (unit bypass, `--filter DisplayPreferencesServiceTest`) → FAIL.
- [ ] Step A2: implement — `formatTime`: `$fmt = $this->getTimeFormat() === '12h' ? ($withSeconds ? 'g:i:s A' : 'g:i A') : ($withSeconds ? 'H:i:s' : 'H:i');`; `formatDateTime` forwards the flag. Extension filters gain the optional arg. Run → PASS. Commit: `feat(display): optional seconds in preference-aware time formatting`.

**Commit B — Twig sweep.** Replace ONLY user-facing occurrences (leave internal bucketing keys `'Y-m-d'` in `admin/settings.html.twig:1133` + `dashboard/index.html.twig:601–602`, `'N'`, `'H'`, `'c'` data-attrs in `media/series.html.twig:511,577`, copyright `"now"|date("Y")` ×3, and `radarr/exclusions.html.twig:45` untouched):
- [ ] `'d/m/Y H:i'` (20×) → `|prismarr_datetime` — deluge/index:778, transmission/index:781, media/films_history:67, media/sonarr_system:51, media/series_history:62 (drops `'Europe/Paris'`), jellyseerr/settings/tasks_cache:58, media/series_blocklist:53 (drops tz), media/radarr_system:55, prowlarr/backups:89, media/films_blocklist:59, sonarr/backups:78, prowlarr/system:54, prowlarr/tasks:83+97, radarr/backups:78, sonarr/tasks:29+30, radarr/tasks:29+30, qbittorrent/index:823
- [ ] `'d/m/Y'` (8×) → `|prismarr_date` — jellyseerr/settings/updates:75, media/films_missing:84+86, media/films_cutoff:69, prowlarr/index:196, prowlarr/updates:109, sonarr/updates:67, radarr/updates:67
- [ ] `'d/m/y'` (6×) → `|prismarr_date` — deluge/index:778+792, transmission/index:781+793, qbittorrent/index:823+835 (note: these are the Twig-rendered initial rows; the JS poller rebuilds them — Commit C aligns the JS)
- [ ] `'d/m H:i:s'` (4× logs) → `|prismarr_datetime(true)` — jellyseerr/settings/logs:47, prowlarr/logs:65, radarr/logs:54, sonarr/logs:54
- [ ] `'H:i:ss'` (4×, upstream typo — double seconds) → `|prismarr_time(true)` — sonarr/commands:74+77, radarr/commands:74+77
- [ ] `'d/m/Y H:i:s'` (2×) → `|prismarr_datetime(true)` — media/radarr_system:286, media/sonarr_system:214
- [ ] `'d/m H:i'` (2×) → `|prismarr_datetime` — media/indexeurs:143, prowlarr/history:56
- [ ] `'D d/m H:i','Europe/Paris'` (1×) → `{{ ep.airDate|date('D', display_pref('timezone')) }} {{ ep.airDate|prismarr_datetime }}` — media/series:335 (preserves the weekday)
- [ ] `'M j, Y'` (1×) → `|prismarr_date` — dashboard/_quicklook_body:33
- [ ] Verify: `grep -rn "date('d/m" symfony/templates` returns nothing; `grep -rn "Europe/Paris" symfony/templates` returns nothing; `lint:twig` clean. NOTE: after tasks 1–3 merge, re-run the grep — line numbers may shift and new call sites may exist (usenet/_history_rows uses `relative_date`, fine as-is). Commit: `fix(ui): render all dates via the Display date/time preference — upstream #54`.

**Commit C — JS formatters.**
- [ ] In `base.html.twig` next to `_prismarrToast` (~2527): inject prefs + helpers:
  ```js
  window._prismarrDatePrefs = { date: {{ display_pref('date_format')|json_encode|raw }}, time: {{ display_pref('time_format')|json_encode|raw }} };
  window._prismarrFmtDate = function (d) {
      var p = window._prismarrDatePrefs || {};
      if (p.date === 'iso') { var m = ('0'+(d.getMonth()+1)).slice(-2), day = ('0'+d.getDate()).slice(-2); return d.getFullYear()+'-'+m+'-'+day; }
      return d.toLocaleDateString(p.date === 'us' ? 'en-US' : 'fr-FR');
  };
  window._prismarrFmtTime = function (d, withSeconds) {
      var p = window._prismarrDatePrefs || {};
      var opts = { hour: '2-digit', minute: '2-digit', hour12: p.time === '12h' };
      if (withSeconds) opts.second = '2-digit';
      return d.toLocaleTimeString(p.time === '12h' ? 'en-US' : 'fr-FR', opts);
  };
  window._prismarrFmtDateTime = function (d, withSeconds) { return window._prismarrFmtDate(d) + ' ' + window._prismarrFmtTime(d, withSeconds); };
  ```
  (Match the surrounding block's exact script/nonce idiom; if that block is wrapped in a guard, follow it.)
- [ ] Replace the six page-local formatters with calls to the globals (keep a local fallback `|| old code` ONLY if the surrounding code already defends against missing globals — check `_prismarrToast` call sites for the idiom):
  deluge/index:996–997, qbittorrent/index:1051–1052, transmission/index:999–1000 (drop the `LOCALE`-derived `loc` in the date path), prowlarr/index:1213–1214, jellyseerr/settings/tasks_cache:305+539 (currently hardcoded `fr-FR`), media/films:2168 (hardcoded `fr-FR`) and 3584 (ETA time → `_prismarrFmtTime`).
- [ ] `lint:twig` all touched templates. Commit: `fix(ui): client-side date/time rendering honors the Display preference — upstream #54`.

**Full-suite gate (overseer runs this):** booted container + `vendor/bin/phpunit` full suite green before the set is presented.

---

## Self-Review Notes

- Spec coverage: #35 → Task 1 (+ Prowlarr title links folded into Task 3, same spirit); #47 → Task 2 (age pill included; NZBGet kept working); #71 → Task 3; #54 → Task 4 Twig + JS + seconds support. ✔
- Type consistency: `completedAt: ?int` epoch everywhere; `grab(): array` requestWithError shape end-to-end; `prismarr_time(true)` matches new signature. ✔
- Ordering: Task 4 depends on 1–3 being merged (shared files: prowlarr/index, films, series, qbittorrent/deluge/transmission templates). Tasks 1–3 are pairwise disjoint. ✔
