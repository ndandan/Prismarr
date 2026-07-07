# Fork changes — what differs from upstream Prismarr

The running record of how this fork (`ndandan/Prismarr`) differs from the
upstream project ([Shoshuo/Prismarr](https://github.com/Shoshuo/Prismarr)).
Everything below is merged to `main` and published to
`ghcr.io/ndandan/prismarr:latest`.

*Last updated: 2026-07-05 (covers 2026-06-13 → 2026-07-04).*

**How the fork works:** upstream is merged in regularly, upstream-origin code
is left untouched even when fork changes obsolete it (so the fork stays
mergeable both ways), and everything general-purpose is offered back as an
upstream PR. Every change lands through the same quality gate as upstream —
PHP lint, Twig lint and the full PHPUnit suite (~700 tests) green in CI, plus
a live test on a real Unraid deployment — before an image is published.

---

## 1. Contributed back — merged upstream (2026-06-26)

These started as fork work (they were the bulk of earlier versions of this
document) and are now part of the original project, so they no longer
represent a diff:

- **Library-page performance rework** — [#56](https://github.com/Shoshuo/Prismarr/pull/56).
  Short-TTL (45 s) per-instance cache on the heavy `getMovies()` / `getSeries()`
  payloads (`MediaLibraryCache`, write-through invalidation, empty results not
  cached) plus a `curl_multi` batch (`multiGet()`) for the per-page status /
  queue / indexers / health / calendar calls. Warm revisits ~3× faster; a slow
  instance costs one timeout window instead of stacking one per call.
- **CI / workflow modernisation with fork-friendly guards** — [#59](https://github.com/Shoshuo/Prismarr/pull/59).
  All pinned GitHub Actions bumped to current majors, `workflow_dispatch` on
  CI, and the Docker Hub README sync guarded with
  `if: github.repository == 'Shoshuo/Prismarr'` so forks don't fail on missing
  secrets.
- **Plex activity via Tautulli + latency-aware health chips** — [#60](https://github.com/Shoshuo/Prismarr/pull/60).
  The optional read-only Tautulli integration: "Current Plex activity"
  dashboard widget (streams, transcode breakdown, bandwidth, per-session
  cards) and the full Plex Activity page (now playing, watch statistics,
  graphs, history, libraries), with the API key kept server-side and every
  response sanitised. Plus `HealthService::statusFor()`: five-state
  latency-aware health chips (up / slow / very_slow / down / degraded) with a
  round-trip reading.
- **In-place quick-look modal on the dashboard** — [#61](https://github.com/Shoshuo/Prismarr/pull/61).
  Clicking any dashboard media tile opens a read-only detail modal in place
  (poster, status, rating, genres, synopsis, Manage/Discover deep-link)
  instead of navigating away; server-rendered fail-open fragments reusing the
  dashboard's library cache.
- **Plex Activity statistics, graphs and per-user filter** — [#62](https://github.com/Shoshuo/Prismarr/pull/62).
  Most-popular tiles, Play Count ⇄ Play Duration toggle, four new graphs, a
  privacy-safe Users overview table, and a page-wide per-user filter — all
  read-only, allow-listed, fail-open.

---

## 2. Shipped in the fork, proposed upstream (PRs open)

Already on the fork's `main` and `:latest`; each has an open upstream PR.

### Dashboard themes — [#66](https://github.com/Shoshuo/Prismarr/pull/66)

Glance-style theming (shipped 2026-06-24). One admin-chosen instance theme in
Settings → Display: 17 presets using the full glance colour model (HSL
background/primary/positive/negative, contrast and text-saturation
multipliers, a `light` flag). `ThemeService` resolves the preset into concrete
CSS variables **server-side** — no flash of unthemed content — injected into
`base.html.twig`; the accent picker gains a `theme_default` option. The
default preset, `midnight`, reproduces the pre-themes dark look exactly, so
upgrading is a visual no-op until an admin picks something else.

Two non-obvious pieces: Turbo Drive keeps the persistent `<html>` element
across visits, so the themed `:root` block is `data-turbo-track="reload"` (a
theme change forces one full reload; normal navigation stays fast), and the
sticky topbar uses a themed `--prismarr-topbar-bg` instead of a hardcoded
near-black.

**New code:** `App\Theme\ColorMath`, `App\Theme\ThemePresets`,
`ThemeService::resolve()`, `ThemeExtension`, a `display_theme` setting kept in
sync with the preset table by a test.

### Dashboard layout customization — [#68](https://github.com/Shoshuo/Prismarr/pull/68)

Reorder + hide/show every dashboard section (shipped 2026-06-26). Global,
admin-only, two editing surfaces: an on-dashboard **edit mode** (native HTML5
drag-to-reorder, per-section Hide buttons, Save/Cancel — no new JS
dependency) and a "Dashboard layout" card in `/admin/settings` mirroring the
sidebar-visibility pattern. The hero stays pinned at top.

**New code:** a `DashboardSections` static registry, a
`DashboardLayoutService` resolver (config keys `dashboard_section_order` CSV +
`dashboard_hide_<key>`, mirroring the sidebar keys), dashboard sections
extracted into `dashboard/sections/_*.html.twig` partials rendered from a
data-driven loop (each self-gates on its service being configured), and a
`POST /admin/settings/dashboard-layout` endpoint. Edit mode skips zero-height
(empty/gated-out) section wrappers so they can't grow phantom drag handles.

### One unified detail modal everywhere — [#69](https://github.com/Shoshuo/Prismarr/pull/69)

Shipped in three slices (2026-06-26/27), prompted by maintainer feedback on
the original search-modal PR ("one modal, less code to maintain"):

- **Search results open the rich modal.** Top-bar `Ctrl+K` results open the
  quick-look instead of navigating, with movie theater/digital/physical
  release dates and series air status (first/next/ended) as styled chips, and
  Add (quick-add picker) / Manage actions.
- **The quick-look became app-global** (shared partial + delegated handlers in
  `base.html.twig`) and was **enriched** with cast, watch providers, trailer
  link, TMDb/IMDb links and a watchlist toggle — all extracted from the TMDb
  payload the endpoints already fetched (`append_to_response`), so zero extra
  API calls.
- **The Explorer/Discovery modal was retired** (~270 lines deleted) and the
  Explorer page repointed at the global quick-look. The TMDb quick-look
  view-model now cross-references the library (fail-open scan of the cached
  libraries), so in-library items show a status badge + Manage deep-link and
  everything else gets a first-class Add button.

Also open upstream: [#70](https://github.com/Shoshuo/Prismarr/pull/70), a
small fix stopping the global-search icon overlapping its text in compact
density.

### Plex items open the global quick-look — [#75](https://github.com/Shoshuo/Prismarr/pull/75) (stacked on #69)

Clicking a title on the Plex Activity page or the dashboard Plex widget now
opens the same global quick-look modal as everywhere else, replacing the
bespoke Tautulli metadata pop-up. The click resolves the item's TMDb id
server-side (`GET /tautulli/api/quicklook/{ratingKey}`, one `get_metadata`
call — seasons resolve via parent guids, episodes via grandparent guids, with
one metadata hop to the show for older payloads that lack show-level guids),
and only the numeric TMDb id reaches the browser, preserving the existing
guid allow-list stance. Items with no TMDb match (music, home videos) fall
back to the legacy Plex modal so every click keeps working.

### Performance batch — [#74](https://github.com/Shoshuo/Prismarr/pull/74)

- **Cross-request service-health cache.** `HealthService`'s 10 s verdict memo
  lived in a per-object array, which classic (non-worker) FrankenPHP throws
  away every request — so every topbar/dashboard poll, from every open tab,
  re-pinged all services with sequential blocking calls. Verdicts now share
  `cache.app` (same 10 s TTL): at most one probe sweep per window for the
  whole install, with a generation token so "Test connection" / settings saves
  invalidate instantly.
- **Browser cache headers on static assets.** Caddy serves the content-hashed
  `/assets/*` with `immutable, max-age=31536000`, and the `/static/*` vendor
  bundles + `/img/*` with a one-day TTL — repeat loads stop re-negotiating
  ~600 KB of CSS/JS.
- **Prod cache pre-warmed at image build.** The Dockerfile runs
  `cache:warmup` after `asset-map:compile`, so the first request after a
  container (re)start no longer pays the 1–3 s container/route/Twig compile.
  Env vars stay runtime-resolved placeholders, so the boot-generated
  `APP_SECRET` doesn't invalidate the baked cache.

### Deluge tab — [#76](https://github.com/Shoshuo/Prismarr/pull/76)

A full torrent-management `/deluge` page (shipped 2026-07-05) mirroring the
qBittorrent tab, via the deluge-web JSON-RPC API: live table with server-side
pagination/filter/sort/search, read-only Label filter (labels stay owned by
Sonarr/Radarr), seeding-focused Ratio / Uploaded / Completed columns, detail
panel (files, trackers, peers) with Radarr/Sonarr resolve, single/bulk
actions, session-wide Pause/Resume All, add via magnet/URL/.torrent
(SSRF-guarded, bencode-validated), per-torrent + global speed limits, sidebar
badge with completion toasts. The client judges success on the JSON-RPC
envelope (deluge-web answers HTTP 200 even on failure), auto-reconnects a
daemon-disconnected web UI, supports reverse-proxy auth (empty password) and
sits behind the same circuit breaker + SSRF locks as every other client.

---

## 3. Shipped in the fork, not yet proposed upstream

On the fork's `main`; candidates for a future PR wave.

### Houndarr widget (2026-07-04)

Optional read-only [Houndarr](https://github.com/av1155/houndarr) integration
(URL + API key in `/admin/settings`, per-service kill switch, Test
connection). A dashboard section — participating in layout customization like
every other — with:

- **Stat tiles** for the backlog-search totals: Tracked, Eligible
  (highlighted), Cooldown, Unreleased and 7-day Searches, hydrating
  asynchronously and refreshing every 60 s.
- **A stacked library-health bar** built from those totals.
- **Per-instance Radarr/Sonarr "wanted" rows** — Wanted + Cutoff-unmet counts
  pulled from the fork's own *arr clients (45 s cached), matching the numbers
  Houndarr itself displays.
- **A Houndarr chip** in the unified health list (topbar + dashboard).

Houndarr's API key authorizes exactly one endpoint (`GET /api/v1/widget`), so
the client is allow-list-normalized end to end, caches results for 45 s — a
rejected key is cached too, so polling can't trip Houndarr's per-IP 429
lockout — and fails open: an unreachable Houndarr renders a muted offline
state and never breaks the dashboard. No dedicated page, since the API exposes
nothing richer. Whether to propose this upstream is undecided — it's
service-monitoring-adjacent, which the maintainer has signalled is out of
scope (see the Unraid widget below).

### UniFi Network widget (2026-07-05)

Admin-only dashboard section pulling from the **UniFi OS Network API** (read-only
local API key, configured in `/admin/settings` with kill switch, optional
TLS-verify skip and Test connection). Non-admins never trigger an API call — the
route and partial are both role-gated.

- **WAN tile:** live download and upload bandwidth (polled every 2 s).
- **Clients tile:** client count split by wired, wireless and guest networks.
- **24-hour usage chart:** inline-SVG graph showing bandwidth over time,
  refreshing every 2 s with a moving 24-hour window, populated via a
  server-side-cached (20 s) statistics endpoint.
- **Infrastructure row:** per-device status chip (gateway, switches, APs;
  green running / orange degraded / grey unreachable), gateway CPU/RAM % with
  threshold styling, all cached for 20 s.

The client caches statistics for 20 s, queries the network group independently
so one failure doesn't blank the rest, fails open, and fail-fasts the remaining
queries when the host itself is unreachable (~15 s → ~3 s first paint after an
outage).

**Files:** `symfony/src/Service/Media/UnifiClient.php`, `symfony/src/Dashboard/NetworkUsageChart.php`,
HealthService and AdminSettingsController edits (UniFi chip registration, admin settings card),
DashboardController edit (network section registration), dashboard templates
(`templates/dashboard/sections/_network.html.twig`), translations
(`translations/en/messages.en.yaml`, `translations/fr/messages.fr.yaml`).

---

## 4. Fork-only — declined upstream

### Unraid server widget (v1 2026-07-01, v2 2026-07-02)

Offered to upstream and **declined 2026-07-04** — the maintainer found it
"really impressive and stylish" but outside Prismarr's media focus (and not
all users run Unraid). It stays a permanent fork feature.

An admin-only "Server" dashboard section pulling from the **Unraid 7 GraphQL
API** (read-only viewer-scoped key, configured in `/admin/settings` with kill
switch, optional TLS-verify skip and Test connection). Non-admins never
trigger an API call — the route and partial are both role-gated.

- **Array tile:** array state, capacity, per-disk health; a stopped array
  shows a neutral badge, not a false alarm.
- **Parity tile:** live check progress with an ETA computed from the API's
  own throughput counters (`mdResyncDb/mdResyncDt` — the same estimate
  Unraid's footer shows), plus the last-check summary (date, duration,
  errors). Two hard-won correctness fixes: the progress denominator falls
  back to `parities[].size` because `unraid-api` types `mdResyncSize` as a
  32-bit Int and nulls it on large arrays; and the last-check line is
  synthesized from the kernel's `vars.sbSynced2` stamp when `parityHistory`
  lags — Unraid only appends its parity log while the webGui's Main page is
  open in a browser, so the history can trail a finished check by days.
- **System tile:** CPU/RAM, uptime (the API returns a boot timestamp, not a
  duration), OS/version.
- **Docker row:** every container as a status chip (green running / grey
  stopped, alphabetical).
- **UPS tile:** charge, load and estimated runtime (the API reports seconds;
  the widget converts).

The client queries each group independently so one failing group doesn't
blank the rest, caches for 20 s, fails open, and fail-fasts the remaining
groups when the host itself is unreachable (~15 s → ~3 s first paint after an
outage).

**Unified health chips** (shipped with v2): `HealthService::chips()` is now
the single chip-list builder — brand colours, latency, five-state dots,
SABnzbd/NZBGet included, Unraid admin-gated — feeding the dashboard health
section, a rewritten topbar health popover, and `/api/health/services` (new
`chips` key; the legacy `services`/`instances` keys are kept for upstream
parity).

---

## 5. Fork infrastructure

- **GHCR publishing** (`ghcr.yml`): push to `main` rebuilds
  `ghcr.io/ndandan/prismarr:latest` (amd64 — the target deployment is
  Unraid); a manual dispatch from a feature branch publishes **only**
  `ghcr.io/ndandan/prismarr:beta`, which is how features get live-tested on a
  real deployment before merging. Upstream's `beta.yml`/`release.yml` Docker
  Hub paths are left untouched (they no-op on the fork without Docker Hub
  secrets).
- **`.gitattributes`** pinning `docker/**` and `*.sh` to `eol=lf`, so a
  Windows checkout can still `docker build` a bootable image (CRLF in the
  s6-overlay control files makes `s6-rc-compile` reject every service).
- **README** rewritten for the fork (fork identity, GHCR install
  instructions, upstream-contribution status); this document tracks the
  detail.

Not fork work, but worth knowing it's included: the fork's `main` contains
everything upstream shipped through 2026-06-26, including upstream's
early-session-release fix for the Unraid FUSE (`/mnt/user`) lockup and the
Gluetun `X-API-Key` fix.

---

## Verification

- Full gate green in CI on `main`: PHP lint, Twig lint, and the PHPUnit suite
  (~700 tests as of July 2026). GHCR `:latest` rebuilds automatically on every
  push to `main`; CI runs independently, so tests are verified green *before*
  pushing.
- Every feature above was live-verified on a real Unraid deployment (usually
  via a `:beta` build of its branch) before merging: themes across presets and
  light/dark, layout edit-mode end-to-end (reorder, hide, persist, cancel),
  the unified modal from search/Explorer/dashboard/Plex surfaces, the Unraid
  widget against a real Unraid 7 box (including a live 44 h parity check for
  the progress/ETA/last-check paths), and the Houndarr widget against a live
  Houndarr install (its per-instance numbers cross-checked against Houndarr's
  own UI).
