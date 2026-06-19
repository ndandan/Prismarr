# Fork changes — what differs from the original Prismarr

Summary of the work done on **2026-06-13 → 2026-06-16**, framed as how this
fork (`ndandan/Prismarr`) now differs from the upstream project. All of it is
merged to `main` and published to `ghcr.io/ndandan/prismarr:latest`.

**Scope:** 56 files changed, ~4,250 insertions since the `1.1.1` baseline
(2026-06-10). Four areas: a new Tautulli/Plex activity integration, a
performance rework of the Radarr/Sonarr library pages, build/CI hardening, and
a latency-aware service health widget.

---

## 1. Tautulli — Plex activity integration (new feature)

A brand-new optional integration that surfaces live Plex activity and watch
history, consuming an existing Tautulli instance's read-only API. None of this
exists upstream.

**Dashboard widget**
- "Current Plex activity" widget: active stream count, Direct Play / Direct
  Stream / Transcode breakdown, total/LAN/WAN bandwidth (Mbps), and one card
  per session (title, user, player/device, quality, decision badges, location,
  progress, play state).
- Plex poster art per session, served through a server-side image proxy
  (`pms_image_proxy`) so the API key never reaches the browser and the path is
  allow-listed to Plex `/library` images.
- "Now playing / Recently watched" tabs (recently-watched defaults in when
  nothing is streaming).
- Live stream-count pill under the sidebar logo, linking to the dashboard.
- Polls every 10 s and fails open: disabled / unconfigured / unreachable /
  wrong-key Tautulli shows a clean message instead of breaking the dashboard.

**Full "Plex Activity" page** (own sidebar entry, route-guarded)
- Live streams, watch statistics (most-watched movies/shows, most-active
  users, top platforms with a 7/30/90-day toggle), a plays-over-time chart,
  paginated watch history, and library counts.
- Every title is clickable into an in-app info modal (synopsis, ratings,
  cast/crew, stream detail) pulled server-side from `get_metadata`.

**Settings & health**
- Configured under Settings → Services → Monitoring (URL + API key, enable
  toggle, Test-connection button) with a dashboard health chip.

**Security / design**
- Read-only by design — no mutating Tautulli commands. Every call is
  server-side; the API key never reaches the browser; responses are sanitized
  to a normalized shape (no IPs, tokens, machine ids, file paths, or raw
  payload) before leaving Prismarr.
- Backed by `get_activity`, `get_metadata`, `get_history`, `get_home_stats`,
  `get_plays_by_date`, `get_libraries`.

**Activity-page enhancements (2026-06-16)**
- Stream-summary strip above Now Playing on the Plex Activity page (session
  count, Direct Play / Direct Stream / Transcode breakdown, total/LAN/WAN
  bandwidth). The dashboard widget's summary block was extracted into a shared
  `dashboard/_plex_summary.html.twig` partial and reused here.
- Richer live stream cards (shared, so the dashboard widget benefits too): an
  HDR/SDR dynamic-range badge, and on transcoding streams the source→target
  codec transition (e.g. `Video: HEVC → H264`) instead of just the decision
  word.
- Decluttered layout: the History section is now a dense full-width grid
  (poster + title + user/when, no progress bar), and Libraries is a slim
  single-column list. The "Recently added" idea was dropped — the *arr apps
  already surface that.
- New graphs, all reusing the `{categories, series}` chart contract and the
  7/30/90-day toggle: a Media Type ⇄ Stream Type toggle on the plays chart,
  plays by hour of day and day of week, and a platform × stream-type "problem
  clients" chart. The aggregate "Total" series is dropped from all charts.

**New code:** `TautulliController`, `TautulliClient`, a `relative_date` Twig
filter (`RelativeDateExtension`), shared Plex partials (session card, history
row, info modal, metadata, styles), the full `tautulli/` page templates, and
the Tautulli service icon. Covered by `TautulliClientTest`,
`TautulliControllerTest`, `RelativeDateExtensionTest`.

---

## 2. Radarr/Sonarr library pages — performance rework

Fixes the 10–15 s library-page loads reported in the project Discord.

- **Short-TTL library cache** (`MediaLibraryCache`): the heavy
  `getMovies()`/`getSeries()` payload is cached per instance for 45 s instead
  of being re-fetched and re-normalized on every visit. Empty results are not
  cached (so a transient failure isn't pinned for the window), and library
  mutations write-through-invalidate so user changes show immediately.
- **Concurrent endpoint fetch** (`multiGet()` on both clients): status, queue,
  indexers, health and calendar are fetched in one `curl_multi` batch instead
  of sequentially. A briefly-unreachable service now costs one timeout window
  for the whole page instead of stacking one per call. Same per-handle
  semantics as the existing `get()` (SSRF protocol guard, 4 s connect / 8 s
  total timeout, per-instance circuit breaker).
- Public normalizers extracted (`normalizeMovies` / `normalizeQueueRecords` /
  `normalizeSeriesList` / `normalizeCalendarEntries`) so the controller reuses
  the exact transforms on batch payloads (no behavior change).
- `MediaController::films()` / `series()` rewired to the cache + batch.

**New code:** `MediaLibraryCache` with `MediaLibraryCacheTest`,
`RadarrClientMultiGetTest`, and a `MediaLibraryPageTest` smoke test (renders
cleanly when the *arr is unreachable — never 500s, never hangs).

**Measured** (LAN, single-run, browser Resource Timing): the Radarr library
page loads in **~5.0 s cold** (cache miss) and **~1.4 s on a warm revisit**
within the 45 s window — about **3× faster** on the common navigate-away-and-back
path. Upstream has no cache, so every visit re-fetches (**~4.3 s**, with no warm
speedup — confirmed by an immediate re-measure). Cold first-load times are
otherwise comparable to upstream; the rework's wins are warm revisits and
graceful degradation when a service is slow (the concurrent `multiGet` replaces
the stacked per-call timeouts behind the original 10–15 s reports).

---

## 3. Build & CI hardening

- **Self-hosted Chart.js** — the Plex plays chart (and the existing Radarr
  stats chart) now load Chart.js from `/static` instead of a CDN, so they
  render under the app's strict Content-Security-Policy.
- **GHCR publish workflow** (`ghcr.yml`) — builds the FrankenPHP image and
  pushes `ghcr.io/<owner>/prismarr:latest` on push to `main` (amd64), using the
  built-in `GITHUB_TOKEN`. Adds a `workflow_dispatch` trigger for manual runs.
- **`.gitattributes`** pinning `docker/**` and `*.sh` to `eol=lf`. Without it a
  Windows checkout (`core.autocrlf=true`) gives the s6-overlay control files
  CRLF, so a local `docker build` produces an image where `s6-rc-compile`
  rejects every service and the container never boots.
- **GitHub Actions bumped to Node 24 runtimes** (GitHub forces Node 20 off on
  2026-06-16): `checkout` v4→v5, `setup-qemu-action` v3→v4,
  `setup-buildx-action` v3→v4, `metadata-action` v5→v6, `login-action` v3→v4,
  `build-push-action` v6→v7, `action-gh-release` v2→v3,
  `dockerhub-description` v4→v5.

---

## 4. Latency-aware service health widget

The dashboard "Services health" card moves from a binary Up/Down chip to a
five-state, latency-aware status. None of this exists upstream.

- **Five states with a response-time reading** rendered as `Status · N ms`:
  `up` (live ping ≤ 750 ms), `slow` (751–2000 ms), `very_slow` (> 2000 ms),
  `down` (a live ping that times out / is refused / fails auth), and `degraded`
  (a stale verdict served from the cross-request circuit breaker without a fresh
  live probe — i.e. "cached stale data"). Latency is shown only for the three
  reachable states; dots are colour-coded per state (green → amber → orange,
  slate-gray for degraded, red for down).
- **New `HealthService::statusFor()`** times a live ping with a monotonic clock
  (`hrtime`) and classifies it; results are cached in-process for 10 s like the
  legacy path. The existing `isHealthy()` boolean now delegates to it and
  projects back to `?bool`, so the topbar indicator and `/api/health/services`
  JSON keep their exact prior contract (verified live — the API still returns
  `bool|null` per service with no latency leak).
- Scope was deliberately limited to the dashboard widget; clients' `ping()` and
  the circuit breaker are untouched.

**New code:** `HealthService::statusFor()` + `classifyLatency()`, reworked
`DashboardController::servicesHealth()`, updated `_health.html.twig` + dot-colour
CSS, and `dashboard.health.status_*` translation keys (en/fr). Covered by
`HealthServiceStatusTest` (latency buckets, down, degraded, not-configured,
`isHealthy` projection, cache-hit) and an updated `DashboardControllerTest`.

---

## 5. Dashboard in-place quick-look

Every clickable media tile on the dashboard used to navigate to another page just
to show its detail modal. They now open a read-only "quick-look" detail modal in
place on the dashboard. None of this exists upstream.

- **One modal, two sources.** Library tiles (hero spotlight, recently-added,
  upcoming/calendar) open a Radarr/Sonarr quick-look — poster/backdrop, title +
  year, a `downloaded` / `monitored` / `missing` status badge, rating, runtime
  (movies) or network (series), genres, synopsis, and a "Manage on Radarr/Sonarr
  →" deep-link into the full library editor. TMDb tiles (weekly trending,
  watchlist) open the same card with a "View in Discover →" link and no library
  status badge.
- **Server-rendered, fail-open fragments.** Two endpoints under
  `/tableau-de-bord/quicklook/*` build a common view-model and render one shared
  `_quicklook_body.html.twig`. The library builder reuses the dashboard's existing
  45 s library cache (usually zero extra upstream calls) and falls back to a single
  `RadarrClient::getMovie` / `SonarrClient::getSerie`; the TMDb builder uses the
  1 h-cached `TmdbClient`. On a miss or upstream error each endpoint returns a small
  graceful body, never a 500.
- **Progressive enhancement.** Every tile keeps its original `href`; a delegated
  click handler intercepts only when it can build a valid quick-look URL and
  otherwise (JS off, missing data, failed/empty fetch) lets the link navigate to
  the relevant tab exactly as before. The modal is a manual (Bootstrap-less) dialog
  matching the dashboard's other delegated handlers, closing on ×/backdrop/Esc.

**New code:** `DashboardController::quickLookLibrary()` / `quickLookTmdb()` /
`findLibraryRow()` / `tmdbImage()` and the two `app_dashboard_quicklook*` routes;
`dashboard/_quicklook_modal.html.twig` + `_quicklook_body.html.twig`; the modal
CSS + delegated fetch/open/close JS in `dashboard/index.html.twig`; `data-ql-*`
attributes on the hero, recent-additions, upcoming, recommendations and watchlist
tiles; `slug` / `ql*` fields added to the dashboard tile data; and
`dashboard.quicklook.*` translation keys (en/fr ICU). Covered by added
`DashboardControllerTest` cases (library movie/series view-models, cache-miss
fallback, unknown id, TMDb movie/tv mapping incl. 0-season, hero quick-look
fields).

---

## Verification

- Full gate green in CI on `main`: PHP lint, Twig lint (145 files), and the
  PHPUnit suite (599 tests). GHCR image rebuilt and republished on the new
  actions with no remaining deprecation warnings.
- Service health widget verified live on the Unraid deployment: chips render
  `Status · N ms`, latency is re-measured each cycle (network-bound TMDb /
  Tautulli readings drift between samples while local services stay pinned at
  1–3 ms), and `/api/health/services` still returns the unchanged boolean
  contract. The non-`up` states (slow / very_slow / degraded / down) are
  covered by unit tests but not yet exercised against a genuinely slow / downed
  service.
- Outstanding: live verification of the perf work against a real, reachable
  Radarr/Sonarr (only the unreachable-host error path is covered by automated
  tests).
