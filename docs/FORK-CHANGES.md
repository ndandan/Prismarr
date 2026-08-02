# Fork changes — what differs from upstream Prismarr

The running record of how this fork (`ndandan/Prismarr`) differs from the
upstream project ([Shoshuo/Prismarr](https://github.com/Shoshuo/Prismarr)).
Everything below is merged to `main` and published to
`ghcr.io/ndandan/prismarr:latest`.

*Last updated: 2026-08-01 (covers 2026-06-13 → 2026-08-01).*

**How the fork works:** upstream is merged in regularly, upstream-origin code
is left untouched even when fork changes obsolete it (so the fork stays
mergeable both ways), and everything general-purpose is offered back as an
upstream PR. Every change lands through the same quality gate as upstream —
PHP lint, Twig lint and the full PHPUnit suite (~1,080 tests) green in CI, plus
a live test on a real Unraid deployment — before an image is published.
Since 2026-07-31 that gate also includes PHPStan at level 7.

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

- **WAN tile:** live download and upload bandwidth, client-polled every 30 s
  against a 20 s server-side cache.
- **Clients tile:** client count split by wired, wireless and guest networks.
- **24-hour usage chart:** inline-SVG graph showing bandwidth over time with a
  moving 24-hour window, built by a pure geometry helper (`NetworkUsageChart`)
  from the same 20 s-cached overview.
- **Infrastructure row:** per-device status chip (gateway, switches, APs) —
  green when online, red when offline — plus gateway CPU/RAM %, all from the
  same cached overview.

`UnifiClient::overview()` fires one call per endpoint (health / hourly report /
device list) so a missing or drifted endpoint degrades one block instead of
blanking the widget, caches the combined result for 20 s, and fails open: a
transport-level failure (connect refused/timed out, DNS) on the first call
short-circuits the remaining two calls instead of paying their connect timeout
too. Each call runs with an 8 s total / 3 s connect cURL timeout.

**Files:** `symfony/src/Service/Media/UnifiClient.php`,
`symfony/src/Dashboard/NetworkUsageChart.php`, `symfony/src/Dashboard/DashboardSections.php`
(section registration), `HealthService` and `AdminSettingsController` edits (UniFi
chip, admin settings card, kill switch, test fields), `DashboardController` edit
(network widget route), dashboard templates
(`templates/dashboard/sections/_network.html.twig`,
`templates/dashboard/_network.html.twig`, `templates/dashboard/index.html.twig`,
`templates/admin/settings.html.twig`), `unifi.svg`, translations
(`translations/messages+intl-icu.en.yaml`, `translations/messages+intl-icu.fr.yaml`), tests.

### Setup wizard on stock Tabler primitives (2026-07-10)

The first-run wizard (`templates/setup/`) carried its own mini design system —
custom stepper, buttons, card, badges, test-result dots, secret toggles and
section headers. All of it was consolidated onto the stock Tabler components
the rest of the app already loads:

- `.wizard-steps` → Tabler `.steps.steps-counter` (plus one accent rule so
  completed steps render green; a skipped step's predecessor renders indigo via
  stock Tabler sibling selectors — deliberate and informative).
- `.test-result` dots → `.status` pills; `.btn-wizard-*` → stock `.btn`
  variants (the accent gradient survives as `.btn-wizard-accent`);
  `.wizard-card` → `.card`; service recap rows → list-group + badges.
- Secret fields → `input-group` reveal toggle driven by a `[data-toggle-for]`
  JS selector; section headers → `.hr-text.hr-text-left`; eyebrow text →
  `.subheader`; all bespoke form-control overrides deleted.

Net **−268 lines of custom CSS**; every remaining flourish lives in a single
commented "Prismarr accent layer" block in `setup/_layout.html.twig`. One
Tabler gotcha worth keeping on record: Tabler ships **two** unrelated `.steps`
components, and the doc-steps one bleeds `margin/padding/border-left` onto the
stepper — those are explicitly zeroed. Behaviour unchanged: PHPUnit 794/794,
`lint:twig` clean, and all 7 wizard steps live-verified on a fresh empty-DB
container in dark, light and 390 px widths.

**Files:** the eight `symfony/templates/setup/*.html.twig` templates (merge
`968da85`, 4 commits).

### Runtime & performance (2026-07-10 → 2026-07-11)

A batch of runtime work, some general enough to propose upstream later:

- **Opt-in FrankenPHP/Symfony worker mode** (`PRISMARR_WORKER`, default OFF).
  Boots the Symfony kernel once and keeps it resident, skipping the
  per-request bootstrap — the single biggest remaining per-request cost. On the
  homelab it cut a health poll ~46 ms → ~3 ms (~15×) and a large Films render
  ~3.0 s → ~0.3 s (~10×); TMDb-bound pages are unchanged, as expected.
  Symfony 8's default runtime drives FrankenPHP's worker loop natively (no
  `APP_RUNTIME` / extra Composer package). Correctness across the shared-kernel
  boundary rests on every request-scoped service implementing `ResetInterface`;
  this closed the last two gaps (`HealthService`, `DashboardController`), which
  also removed a latent staleness edge in classic mode. `PRISMARR_WORKER_NUM`
  pins the thread count.
- **OPcache preload of the framework + app class graph.** `php.ini` sets
  `opcache.preload` to Symfony's `config/preload.php` (backed by the build-time
  `cache:warmup`), linking ~1,300 classes into shared memory at boot. FrankenPHP
  reports `PHP_SAPI` as `frankenphp` (not `embed`), so Symfony's preload runs
  rather than short-circuiting (verified: 1,336 scripts preloaded).
- **Dashboard poller consolidation.** The dashboard's five live widgets (Plex
  10 s; health/server/network 30 s; Houndarr 60 s) each ran their own
  `setInterval` + fragment request, firing up to four simultaneous requests at
  the 30 s alignment. One coalescing scheduler now ticks at the GCD of the
  cadences and batches every widget due on a tick into a single
  `GET /tableau-de-bord/widgets?w=…` request returning a `{name: html}` map —
  ~13 → ~6 requests/min, aligned burst collapsed to one. Cadences are declared
  per widget via `data-dash-poll`; initial hydration still fetches each fragment
  in parallel for fast first paint; fail-open / hidden-tab / Turbo-singleton
  behaviour is preserved.

### Mobile & settings polish (2026-07-10)

- **Library filters collapse into a drawer on mobile.** Below 992 px the
  Films/Series filter card becomes a Bootstrap offcanvas opened by a
  `Filtres (n)` button, with an **Apply** button that runs a single navigation
  (no reload-per-tap); at ≥992 px the toolbar is inline exactly as before.
  Discover's Filters panel becomes a slide-in drawer at all widths. One
  responsive offcanvas markup drives both states.
- **Settings service-cards pack tightly.** The "Services externes" grid used
  row tracks sized to the tallest card, leaving dead space beside short cards;
  it's now CSS multi-column packing (`columns: 2 320px`) with the multi-instance
  Radarr/Sonarr cards spanning both columns.

### Design-critique & accessibility pass (2026-07-11)

A dual-agent design critique run at the owner's request drove a fork-only
polish round (merge `5e62504`); all additive, several upstream-worthy:

- **Light-preset legibility.** Four of the 17 theme presets set `light`, so
  `data-bs-theme="light"` is reachable — but fork components hardcoded dark
  colour literals (calendar numbers/labels/pills, Jellyseerr hero, Prowlarr
  chips/labels, changelog text, series-detail header). Those are now theme
  tokens (or pinned opaque-dark where the surface is deliberately dark).
- **Calendar quick-look + legend-true colours.** Calendar events now open the
  app-global quick-look in place (library detail for known items, media page
  otherwise) instead of ejecting to `/medias?open=…`; episode blocks recoloured
  to the blue/cyan family that matches their filter-chip legend.
- **Keyboard accessibility.** Media/watchlist/calendar cards that open the
  quick-look gained `tabindex=0` + `role=button` + `aria-label` + a shared
  Enter/Space handler and a `:focus-visible` ring.
- **Tabler round 3.** Sparse `.ribbon` "New" flags on items added ≤7 days, and
  an `.avatar-list` of current Plex viewers on the dashboard widget.
- **Locale-aware TMDb regions.** Release dates, certifications and watch
  providers were hardcoded FR-first; a new `TmdbClient::regionPriority(locale)`
  drives country ordering from the active UI locale (en → US/GB, fr → FR/BE, …)
  with a common fallback, wired through every pick site and unit-tested.
- **Shared component vocabulary.** Three near-duplicated patterns unified into
  reusable partials — `_view_switcher`, `_stat_tiles`, `_filter_pills` — with
  one CSS vocabulary; the infinite `dl-pulse` sidebar animation retired for a
  static glow + reduced-motion-guarded pulse-on-increase.

### UniFi Network tab — `/unifi` (2026-07-25)

A full network-operations page over the **classic** UniFi Network API (merge
`a7daffe`), going well beyond the dashboard widget above. `#[IsGranted('ROLE_ADMIN')]`
gates the whole controller: network topology and per-client detail are not for
regular users. Reuses the widget's existing UniFi settings — no new config keys.

- **Panels.** WAN / gateway / client tiles; 7-day traffic and 30-day speedtest
  trends as **server-rendered SVG** (no charting library shipped to the browser);
  device inventory; RF environment (per-AP radio table plus a neighbouring-AP
  scan); VLAN inventory; wireless clients; live talkers; top clients; and a
  DHCP-reservation mismatch list.
- **Three readers, three cadences.** Each reader owns one endpoint group and its
  own interval — live 10 s, infrastructure 60 s, history 300 s — so every
  upstream endpoint is hit **once per cycle** regardless of how many panels
  consume it. A page-scoped coalescing poller then batches every region due on a
  tick into a single `/unifi/api/panels` request (`/unifi/api/{panel}` with
  `panel ∈ live|infra|history` remains addressable individually).
- **Fail-soft and read-only.** No endpoint mutates anything, and every panel
  answers `200` with an empty state rather than letting a sick console 500 the
  page.
- **Built on a seam so the widget can't regress.** A two-method `UnifiFetcher`
  abstraction sits under both surfaces, so the already-shipped dashboard widget
  is insulated from the tab's much wider endpoint surface.
- **Field map verified against a live console**, not the docs: gateway-only
  `temperatures[]`, radio width from `bw`, a radio `satisfaction` of `-1`
  normalised to `null`, neighbours via `POST stat/rogueap`, and the gateway's
  `lan_ip` preferred over the WAN address in `ip`.

Live-verified on `:beta` in dark and light, desktop and 390 px, with poller
coalescing confirmed and polling confirmed to stop after a Turbo navigation.

Fork-only in practice — it is deep infrastructure monitoring rather than media
management, so it is a poor upstream fit (same reasoning as the Unraid widget in
§4), but nothing in it is fork-specific if upstream ever wants it.

### Transmission tab (2026-07-25)

A third download client alongside qBittorrent and Deluge (merge `79e8ce3`),
reworked from **jeromelefeuvre's fork PR #18** with the contributor's
authorship preserved. Feature parity with the Deluge tab: list/table/compact
views, filtering, search, sort, server-side pagination, bulk actions
(pause/resume/delete/recheck plus pause-all/resume-all), a per-torrent detail
modal (general/files/trackers/peers), add-by-URL and add-by-file, and a
read-only category filter derived from Transmission's own labels. Registered
everywhere a service must be: setup-wizard step, admin settings card with
kill switch, sidebar entry, topbar poll and health circuit breaker.

- **RPC session handshake handled transparently.** Transmission answers a
  request without a valid `X-Transmission-Session-Id` with HTTP `409` carrying
  the real token; the client caches it and retries. The wizard's Test button
  and the health check both treat that `409` as *reachable*, while a genuine
  `401` (bad RPC password) is reported as an auth failure. User/password are
  optional, matching qBittorrent's reverse-proxy-friendly config shape.
- **Reused the qBittorrent translation namespace.** `templates/deluge/` already
  referenced 224 `qbittorrent.*` keys against 5 `deluge.*`, so the fork's real
  convention for a second torrent client is to reuse that namespace rather than
  carve out a private one — which cut the contribution's `transmission:` block
  from 208 keys per locale to 15.
- **Test gap it exposed.** Nothing in CI rendered *any* torrent template:
  `ServiceRouteGuardSubscriber` redirects the torrent routes to the setup
  wizard whenever the service URL is unset (always true under the web test
  case), and the smoke test only asserts `status < 500`, so the 302 passed and
  the body was never parsed — four nonexistent `transmission.*` keys nearly
  shipped as raw dotted strings. `TransmissionRenderTest` now seeds the URL,
  injects a mock client, renders both locales and fails on any leaked
  `qbittorrent.*` / `deluge.*` / `transmission.*` key. qBittorrent and Deluge
  still have no equivalent gate.

Candidate to offer upstream — nothing about it is fork-specific.

### qBittorrent stale-pagination guard (2026-07-25)

Contributed by **[@jeromelefeuvre](https://github.com/jeromelefeuvre)** (fork PR
[#17](https://github.com/ndandan/Prismarr/pull/17), merge `48d30f3`). The 3 s
poll aborts an in-flight request, but `abort()` is a no-op once the response has
already arrived — common against a fast local qBittorrent, where the round-trip
beats the click-to-JS-execution delay. A poll issued for the *previous* page
could then land after the click's own response and repaint the list with stale
rows. Each request now carries a sequence number and only the newest may apply.

Touches the upstream-origin `templates/qbittorrent/index.html.twig`, so it is an
upstream fix as much as a fork one — a good PR candidate alongside the poller fix
below.

### Cross-page torrent poller fix (2026-07-25)

All three torrent pages leaked their 3-second refresh poller across Turbo
navigations. The `setInterval` handle lived in each page script's closure, and
Turbo Drive re-executes the incoming page's `<script>` with a *fresh* closure
while the outgoing page's closure and its live interval survive — so
`clearInterval` could only ever reach the caller's own timer, never the orphan.
Because all three templates render the same element ids (`qbt-list`,
`qbt-stat-total`, …), the orphan's existence guards still passed against its
successor's DOM, so it kept writing its own client's torrents over the page the
user was looking at, two pollers overwriting each other at 3 s apiece. The
`turbo:before-render` cleanup in `base.html.twig` could not help: it clears
named globals, and a closure variable has no name to clear.

Fix: hoist the handle to one shared global, `window._prismarrTorrentPagePollTimer`
(a single name serves all three, since only one torrent page is ever mounted),
routed through a `clearPollTimer()` helper that both `startRefresh()` and
`stopRefresh()` call, and add that name to the `turbo:before-render` cleanup
array so navigating to a **non**-torrent page — which re-executes no torrent
script — stops the interval too. `TorrentPagePollerTest` (11 cases) pins both
halves across all three templates and fails if a closure-scoped `refreshTimer`
ever returns; `TransmissionRegistrationTest` pins the cleanup array literal.

Pre-existing between qBittorrent and Deluge; the Transmission tab added a third
page to the cycle, which is what surfaced it. Live-verified on `:beta` with the
two clients holding distinguishable data — Deluge 54 torrents, Transmission 0:
Deluge → Transmission gave 18 Transmission polls at a clean 3.0 s and **zero**
Deluge polls over 51 s with the count holding at 0 (the bug would have painted
54), the reverse direction held 54, and a torrent page → dashboard nulled the
handle with zero torrent polls in 52 s. Known residue: `refreshCtrl` and
`refreshSeq` are still closure-scoped, so a fetch already in flight when
navigation starts can apply once to the incoming page before its own poller
corrects it on the next beat — a single self-correcting frame, not observable in
the live run. Namespacing the shared element ids per page is the deeper cause
and remains unaddressed.

Applies to an upstream-origin file (`templates/qbittorrent/index.html.twig`) as
well as the two fork-added ones, so the qBittorrent half is an upstream bug —
milder there, since with one torrent page there is no second page to corrupt,
but the poller still survives into every other page. Good upstream PR candidate.

### Code-review remediation, Phase 1 (2026-07-31)

The first phase of a whole-codebase review (merge `9d469ff`) — the ship-blockers
and the systemic findings, ahead of the CSP work below:

- **`/search/online` 500'd on a cold cache.** The action passed a fake cache
  callback, `fn(ItemInterface $item) => ($item->expiresAfter(60)) ?: []`.
  `expiresAfter()` is fluent and returns `$this`, so the `?:` fallback is
  unreachable: a miss returned the cache item itself, `array_column()` got a
  non-array and threw, and the `ItemInterface` was written into the pool for
  60 s. The repro is mundane — save any admin setting (which clears
  `cache.app`), then `Ctrl+K`. Both search actions now share one real index
  builder.
- **Three XSS escaping sweeps.** 14 sinks in the Discover detail modal
  (TMDb-supplied strings), 28 across the three Jellyseerr templates
  (requester-controlled names), 17 across the calendar's four render paths
  (Radarr/Sonarr titles), plus the Explorer's `escHtml` hardened to match its
  Discover sibling. A 58-row `TemplateEscapingGuardTest` pins every site. These
  stop the *injection*; the CSP phase below stops the *execution*.
- **SABnzbd `action()` failed open.** An absent `status` key defaulted to
  `true`, so any unrecognised `200` — a reverse proxy's page, a different
  SABnzbd version — was reported as a successful pause/resume/delete/add. Pure
  actions always carry `status`; its absence is now a logged failure, matching
  `NzbgetClient`'s existing `=== true` strictness.
- **A logging blackout on four integrations.** `TautulliClient::recordError()`
  swallowed the failure it is named for, and the Gluetun, Unraid, UniFi and
  Houndarr clients logged transport and decode failures at `debug` — which
  production Monolog never writes. A misconfigured integration therefore
  rendered its muted offline state with nothing to triage from. `recordError()`
  warns, and 11 `debug` calls become `warning`, all still behind the existing
  enabled/configured guards so an unconfigured service stays quiet.
- **PHPStan level 7 added to `make check`** (and so to CI, with no workflow
  edit). Level 6 is strictly dominated — 812 missing-iterable-annotation errors
  over level 5, zero correctness findings — so the jump is to 7, with the
  annotation debt ignored *by identifier* rather than baselined so new files
  don't inherit it. 17 real bugs were fixed before the 160-entry baseline was
  taken, which is now shrink-only. Headline: `transliterator_transliterate()`
  returns `false` on failure, coerced to `''`, which made the library search's
  `str_contains()` match every item — one bad transliteration silently turned
  search into "match everything". Also an undefined-method call in
  `TmdbController` (the watchlist repository lacked the
  `@extends ServiceEntityRepository<…>` generic its three siblings declare, so
  `find()` analysed as bare `object`), five `foreach` loops over
  `preg_split()`'s `list<string>|false`, and a `preg_quote()` with no delimiter
  argument.

Almost all of it applies to upstream-origin code, so most of this phase is an
upstream fix as much as a fork one — a good PR candidate once the later phases
settle. Phases 3 and 4 (client abstraction and the duplication findings; CSRF
and the avatar IDOR) are still open.

### Enforcing CSP with a session-stable nonce (2026-08-01)

Phase 2 of the same review (merge `de7540e`): `script-src` drops
`'unsafe-inline'` **and** `data:` for a nonce policy, enforced — Report-Only
retired. Phase 1 escaped the sinks; this stops execution, which is the durable
fix for the class. Every inline `<script>` the app emits (118) carries a nonce
and all 18 inline `on*=` handlers were converted to `addEventListener`
registrations, since a nonce cannot rescue an attribute handler — CSP blocks
those unconditionally. `style-src 'unsafe-inline'` deliberately stays: inline
`style=` is pervasive here and style injection is the strictly lower-severity
class.

The approach was chosen over the ES-module migration upstream issue #32 asks
for: nonce-first is a mechanical diff across 96 templates instead of a
months-scale rewrite with maximal merge-conflict surface, and it reaches the
same security outcome.

- **The nonce is session-stable, and that is the load-bearing design
  decision.** One random value per session (`_csp_nonce`), rotated at the login
  boundary by a `LoginSuccessEvent` subscriber, with a per-request value when
  there is no session. Per-request rotation — the textbook answer — breaks
  Hotwire Turbo two independent ways. (1) The importmap polyfill loader is an
  inline script inside a `data-turbo-track="reload"` head element, so a
  changing nonce changes that element's *body text*; Turbo reads the tracked
  head as changed and forces a full reload on every navigation. (2) Turbo
  re-stamps the scripts it re-injects with the newest
  `<meta name="csp-nonce">` value, which the original document's policy — still
  the one being enforced — has never heard of, so they are blocked. Both are
  invisible on a hard refresh, which is why the `:beta` sweeps navigated in-app
  only.
- **CSP headers are gated to HTML responses** (`governsADocument()`). JSON and
  API responses carry no policy — and stop minting an orphan session per
  cookieless poll: the 30 s Docker healthcheck alone was creating ~2,880
  session files a day.
- **One shared page-lifecycle helper.** `window.registerPageLifecycle(init)`
  runs `init` at parse time and invokes the teardown it returns from a
  self-removing `turbo:before-render` listener. It replaced the leaky
  registrations on the base health poller, the Plex pill, Films, Series and
  Discover, which stacked one handler per visit: visiting Discover *K* times
  and then clicking the watchlist star fired *K* POSTs — add, remove, add — and
  the star silently reverted. Exactly one POST now. This promotes a pattern
  thirteen templates already hand-rolled correctly; the duplicated badge
  pollers and the ~198 cross-template duplicate element ids remain deferred.
- **`app.js` no longer imports its CSS from JavaScript.** AssetMapper compiles
  a CSS import into a `data:application/javascript` stub module, and
  `script-src` no longer allows `data:` — so the browser refused the stub and
  with it the entire module graph hanging off it: Turbo, Stimulus (including
  the stateless-CSRF cookie controller), Alpine, Chart.js. The page rendered
  and every piece of scripted behaviour was gone. The stylesheet loads via
  `<link>`; a guard test prevents the regression.

Verified in three layers, because PHPUnit executes no JavaScript and
`lint:twig` does not parse script bodies: `CspNonceGuardTest`,
`PageLifecycleGuardTest` and `CspNonceRenderTest` in CI (1063 tests / 2855
assertions), then a Report-Only `:beta` round, then an enforcing `:beta` round —
two live confirmation cycles, worker mode on, navigating in-app throughout.

Fork-only for now. It is a different (and better) answer to upstream's #32
rather than a drop-in PR, so offering it upstream is a later decision, not a
planned one.

### Fork-aware Updates settings page (2026-08-01)

`/admin/settings#section-updates` tracked **upstream** (merge `5042601`):
`AppVersion` polled Shoshuo/Prismarr's releases feed, whose newest tag predates
most of what this fork ships. So the page told fork users that a two-month-old
release was an "available upgrade" over their strictly newer build, rendered
upstream's release notes as if they described the running software, and printed
Docker Hub upgrade instructions for an image the fork does not publish. A
related wart: `ghcr.yml` stamped every main build with the hardcoded
`1.1.0-tautulli`, so `:latest` misreported its own version no matter what was
in it.

Approach A of three — **SHA-anchored compare**, over date comparison and
registry-digest comparison:

- **Builds stamp their commit.** Main images report `main-<short sha>` and
  carry the full SHA as `PRISMARR_GIT_SHA` (also the OCI
  `org.opencontainers.image.revision` label). Branch dispatches keep
  `beta-<branch>`. A local build leaves it empty and the app hides the
  behind-check entirely — no error, no badge, no guess.
- **A truthful badge.** `AppVersion::commitsBehind()` reads `ahead_by` from
  GitHub's compare API (`<built sha>...main` on the fork), yielding
  *N commits behind fork main* (links to the compare view) or *Up to date with
  fork main*. `isUpdateAvailable()` is redefined as `(commitsBehind() ?? 0) > 0`;
  the old `version_compare` against upstream tags — the actual bug — is gone. A
  SHA GitHub doesn't know (rebased, pruned) returns null and hides the badge
  rather than reporting zero.
- **The fork CHANGELOG is the release notes.** Raw-fetched and bounded to
  `## [Unreleased]` plus the two most recent released sections, through the
  existing `renderBody()` markdown renderer (which escapes first).
- **A date-grouped commit feed** from the same compare payload — one article
  per day, newest first. Both the bucketing *and* the header label run in the
  viewer's timezone; doing the bucketing in UTC and labelling locally (or the
  reverse) puts a commit under a header that contradicts its own displayed
  timestamp.
- **Upgrade instructions that apply:** the `ghcr.io/ndandan/prismarr:latest`
  pull, plus the Unraid **Force Update** note, because Apply alone does not
  re-pull.
- **Upstream demoted** to an informational "last release" block with no
  "available" phrasing anywhere in it, and in the About card the fork
  source/issues rows sit above the retained upstream rows, now explicitly
  labelled *Upstream* — provenance and credit, not an update source.
- **Resilience.** Three GitHub reads, each cached 1 h, each writing a 120 s
  failure marker on a miss. Without the marker a GitHub outage costs stacked
  8 s timeouts on *every* request; with it, one slow render per two-minute
  window. Every block degrades to an honest "unavailable" state — the page
  always renders at least the current version string, and nothing thrown
  escapes `AppVersion`. At most 3 unauthenticated calls per cache hour against
  a 60/hr budget, so no token and no configuration.

Full EN + FR strings; `UpdatesSectionGuardTest` asserts no `Shoshuo` URL
survives outside the labeled upstream block and that both locales carry every
new key. 1079 tests / 2941 assertions; live-verified on `:beta`.

Fork-only by nature — the whole point is that it tracks *this* repository — so
no upstream PR is planned.

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

- Full gate green in CI on `main`: PHP lint, Twig lint, PHPStan level 7
  (baseline shrink-only, added 2026-07-31) and the PHPUnit suite (1,079 tests /
  2,941 assertions as of 2026-08-01). GHCR `:latest` rebuilds automatically on
  every push to `main`; CI runs independently, so tests are verified green
  *before* pushing.
- Every feature above was live-verified on a real Unraid deployment (usually
  via a `:beta` build of its branch) before merging: themes across presets and
  light/dark, layout edit-mode end-to-end (reorder, hide, persist, cancel),
  the unified modal from search/Explorer/dashboard/Plex surfaces, the Unraid
  widget against a real Unraid 7 box (including a live 44 h parity check for
  the progress/ETA/last-check paths), and the Houndarr widget against a live
  Houndarr install (its per-instance numbers cross-checked against Houndarr's
  own UI).
