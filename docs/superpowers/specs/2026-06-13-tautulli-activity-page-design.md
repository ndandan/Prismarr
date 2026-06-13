# Tautulli Activity Page — Design Spec

**Date:** 2026-06-13
**Status:** Approved (brainstorming) — ready for implementation plan
**Related:** builds on the existing Tautulli/Plex-activity integration (`TautulliClient`, the dashboard widget, the info modal, the sidebar streaming pill).

---

## Goal

Give Tautulli a first-class **sidebar nav entry** (like Radarr/Sonarr/Seerr) that opens a dedicated, full **"Tautulli — Plex Activity"** page. The page is a Tautulli-style dashboard: current streams, watch statistics, a plays-over-time graph, watch history, and library counts — and clicking any movie/show anywhere on the page opens the existing in-app info modal.

## Decisions (from brainstorming)

| Decision | Choice |
| --- | --- |
| Page scope | **Full Tautulli-style dashboard** — live + stats + graph + history + libraries |
| Clicked item | **Reuse the existing info modal** (synopsis, ratings, cast/crew, stream detail) |
| Stats time window | **Preset toggle: 7 / 30 / 90 days** (default 30) |
| Layout | **Stacked sections** (single-column flow, full-width sections) |
| Architecture | **Server-rendered page + async fragment hydration** (matches the dashboard & other service pages) |

## Architecture overview

The page route renders a shell plus the cheap "Now Playing" section server-side. Heavier sections hydrate after load from their own endpoints, each independently cached and failing open. Only the **plays graph** returns JSON (Chart.js needs raw data); every other section is a **server-rendered HTML fragment**, so the poster proxy and the modal-trigger markup work with no new client rendering. Client JS is limited to: the 7/30/90d toggle, the 10s now-playing poll, "Load more" history, and drawing/redrawing the chart.

Same security model as the rest of the integration: the Tautulli API key never leaves the server, responses are sanitized to allow-listed shapes, and every endpoint fails open.

---

## Component 1 — Sidebar entry, routing & visibility

**Files:** `symfony/src/Twig/ConfigExtension.php`, `symfony/templates/base.html.twig`, `symfony/src/EventSubscriber/ServiceRouteGuardSubscriber.php`

- **Register Tautulli as a flat service.** Add `'tautulli' => 'tautulli_url'` to `ConfigExtension::SERVICE_KEYS`. Tautulli is already in `HealthService::TOGGLEABLE_SERVICES`, so this immediately enables `service_configured('tautulli')`, `service_visible_in_sidebar('tautulli')`, the per-service enable toggle, and the `sidebar_hide_tautulli` preference — no other wiring needed.
  - Side effect (intended): `service_configured('tautulli')` becomes true app-wide. Verify no template iterates `SERVICE_KEYS` in a way that would surface Tautulli somewhere unintended (e.g. a generic services list). The dashboard widget card is gated on the controller-provided `services_configured.tautulli` (via `HealthService`), not this Twig function, so it is unaffected.
- **Nav `<li>`** in `base.html.twig`, gated on `service_visible_in_sidebar('tautulli')`, with the Tautulli logo (orange), label "Tautulli", active when `app.request.attributes.get('_route') == 'app_tautulli_index'`. Placed near the other monitoring/Plex-related entries.
- **Route guard.** Add a `ServiceRouteGuardSubscriber` RULES entry keyed on the prefix **`app_tautulli_index`** (NOT `app_tautulli_`): `keys => ['tautulli_url', 'tautulli_api_key']`, `index => 'app_tautulli_index'`, `service_id => 'tautulli'`, and `wizard` pointing at the Tautulli settings page (Settings → Services → Monitoring; resolve the exact route name during implementation).
  - **Critical:** the prefix must not match `app_tautulli_api_*`. Those endpoints must stay unguarded so the sidebar pill and dashboard widget pollers keep receiving their fail-open JSON even when Tautulli is unconfigured.

## Component 2 — `TautulliClient` data methods + normalizers

**Files:** `symfony/src/Service/Media/TautulliClient.php`, test `symfony/tests/Service/Media/TautulliClientTest.php`

All methods are read-only Tautulli `get_*` commands routed through the existing `request(array $params)`. Each has a **pure static normalizer** (unit-tested) that allow-lists fields. The `request()` helper already exists and accepts params.

Helper: `private static function clampRange(int $days): int { return in_array($days, [7, 30, 90], true) ? $days : 30; }`

### `getHomeStats(int $days): array`
Wraps `get_home_stats` (`time_range`=days, `stats_count`=5). Returns:
```
{
  topMovies:    [{ ratingKey, title, year, posterPath, plays }],
  topShows:     [{ ratingKey, title, posterPath, plays }],
  topUsers:     [{ userDisplayName, plays }],
  topPlatforms: [{ platform, plays }]
}
```
`normalizeHomeStats(array $data)`: iterate the stat groups, switch on `stat_id` (`top_movies`, `top_tv`, `top_users`, `top_platforms`). Poster from `thumb` / `grandparent_thumb`. `plays` from `total_plays` (int). **Users expose `friendly_name` only** — never `user`, `username`, `email`, `user_id`, or `user_thumb`. Unknown stat groups ignored. Missing groups → empty lists.

### `getPlaysByDate(int $days): array`
Wraps `get_plays_by_date` (`time_range`=days). Returns Chart.js-ready:
```
{ categories: [<"YYYY-MM-DD">...], series: [{ name: <string>, data: [<int>...] }, ...] }
```
`normalizePlaysByDate(array $data)`: `categories` = list<string>; each series → `{ name: string, data: list<int> }`. Drop anything else.

### `getLibraries(): array`
Wraps `get_libraries`. Returns `[{ name, type, count, childCount }]`.
`normalizeLibraries(array $data)`: `name` from `section_name`, `type` from `section_type` (movie/show/artist), `count` from `count` (int), `childCount` from `child_count` (int, e.g. episodes) when present. **Drop** `section_id`, `server_id`, `thumb`, `art`, and any path/host fields.

### `getHistory(int $length = 8, int $start = 0): array`
Extend the existing method with a `$start` offset (passed to `get_history`). Reuses `normalizeHistory` unchanged. Used by the dashboard widget (`length=8, start=0`) and the page ("Load more" pagination).

### Range validation
Every range-bearing method clamps via `clampRange()`; no arbitrary value reaches Tautulli.

## Component 3 — `TautulliController` endpoints

**File:** `symfony/src/Controller/TautulliController.php` (update the class docblock — it currently claims only `get_activity` is exposed).

All GET, `ROLE_USER`, fail-open. Existing `/api/activity`, `/api/metadata`, `/api/image` are reused unchanged.

| Route | Name | Returns | Notes |
| --- | --- | --- | --- |
| `/tautulli` | `app_tautulli_index` | HTML page | Renders shell + server-side Now Playing. Guarded (Component 1). |
| `/tautulli/api/now-playing` | `app_tautulli_api_now_playing` | HTML fragment | Full-width live cards. Polled every 10s. |
| `/tautulli/api/stats` | `app_tautulli_api_stats` | HTML fragment | `?range=` ∈ {7,30,90}, default 30. Re-rendered on toggle. |
| `/tautulli/api/plays` | `app_tautulli_api_plays` | **JSON** | `?range=`. Feeds Chart.js. |
| `/tautulli/api/history` | `app_tautulli_api_history` | HTML fragment | `?length=25&start=0`. "Load more" appends. |
| `/tautulli/api/libraries` | `app_tautulli_api_libraries` | HTML fragment | Loaded once. |

Each action wraps its `TautulliClient` call in try/catch and renders the fragment's clean empty/error state (or empty JSON `{categories:[],series:[]}`) on any failure — a down Tautulli leaves the page intact with empty sections.

## Component 4 — Templates & shared partials

**New page:** `symfony/templates/tautulli/index.html.twig` (extends `base.html.twig`). Stacked sections:
1. **Header** — page title + a 7/30/90d toggle button group (`data-tautulli-range`, buttons carry `data-range="7|30|90"`).
2. **Now Playing** — `data-tautulli-now`, server-rendered include on load, polled via `/api/now-playing`.
3. **Stat tiles** — `data-tautulli-stats`, hydrated from `/api/stats?range=30` on load and on toggle.
4. **Plays over time** — `<canvas id="tautulli-plays">`, drawn from `/api/plays?range=30`; destroyed/recreated on toggle.
5. **History** — `data-tautulli-history` list + a "Load more" button; **Libraries** — `data-tautulli-libraries`, fetched once.

**Shared partials (extracted to de-duplicate and keep modal triggers identical across the dashboard widget and the new page):**
- `dashboard/_plex_session_card.html.twig` — one live-stream card (poster + title + badges + progress, with `data-plex-rating-key` triggers). Used by the widget now-pane (`_plex_activity.html.twig`) and the page's `_now_playing` fragment.
- `dashboard/_plex_history_row.html.twig` — one history row (poster + title + user/when + percent bar, with trigger). Used by the widget recent-pane and the page history fragment.
- `dashboard/_plex_info_modal.html.twig` — the modal shell + hidden `data-bs-toggle` trigger + the delegated open/keydown handler. Included by both `dashboard/index.html.twig` and `tautulli/index.html.twig` so clicks work on each page. (Today this markup/JS lives inline in `dashboard/index.html.twig`; move it into this partial and include it in both places.)

**New fragment templates:** `tautulli/_now_playing.html.twig`, `tautulli/_stats.html.twig`, `tautulli/_history_rows.html.twig`, `tautulli/_libraries.html.twig`.

**Page JS (scoped IIFE in `tautulli/index.html.twig`):**
- Range toggle: set active button, fetch `/api/stats?range=N` (swap fragment) and `/api/plays?range=N` (redraw chart).
- Now-playing poll: every 10s, skip when `document.hidden`, fail-open (keep last good render).
- "Load more": track `start`, fetch `/api/history?length=25&start=N`, append rows, hide the button when a page returns fewer than `length` rows.
- Chart: Chart.js line (one series per media type), loaded the same way `radarr/stats.html.twig` loads it; `chart.destroy()` + recreate on range change.

The shared delegated modal handler (already document-level) catches clicks from server-rendered triggers in every section automatically.

## Component 5 — Security & fail-open

- API key stays server-side; the browser only ever sees sanitized fragments/JSON and proxied image bytes.
- Every new normalizer is strict allow-list. Forbidden everywhere: IPs, tokens, machine/server ids, file paths, `guid`/`plex://`, usernames, emails, `user_id`, avatars, `section_id`.
- Each endpoint fails open independently (clean empty/error state; never a 500). A disabled/unconfigured/unreachable/wrong-key Tautulli yields empty sections, page intact.
- `range` allow-listed to {7,30,90} before reaching Tautulli; `length`/`start` cast to bounded ints.
- Reuses the existing `\d+` ratingKey route requirement and the image-proxy SSRF allow-list. `set_time_limit` on the page action mirrors `widgetPlex`.

## Component 6 — Testing & rollout

- **Unit (PHPUnit, in-container):** `normalizeHomeStats`, `normalizePlaysByDate`, `normalizeLibraries`, and `getHistory` offset — each asserting (a) correct field mapping and (b) sanitization (forbidden keys absent; serialized output contains no `plex://`, `/data/`, username/email substrings). Mirror the existing `TautulliClientTest` fixtures/style.
- **Controller smoke (PHPUnit):** page route renders; route is guarded when unconfigured; fragment/JSON endpoints return neutral shapes when unconfigured. Mirror `DashboardControllerTest`.
- **i18n:** en + fr keys — page title, section headings (Now Playing / Statistics / Plays over time / History / Libraries), toggle labels, empty states, "Load more", stat-tile labels (Top Movie / Top Show / Top User / Top Platform).
- **Lint/CI:** `lint:twig`, `lint:yaml`, `make check` green on CI.
- **CHANGELOG:** extend the unreleased Tautulli entry (sidebar nav + full activity page).
- **Manual (Unraid rebuild):** sidebar entry appears only when configured and respects the hide toggle; all sections render and fail open; 7/30/90d toggle updates stats + graph; "Load more" paginates and stops; chart draws; the info modal opens from a live card, a history row, and a most-watched tile.

---

## Out of scope (YAGNI)

- Per-user detail pages, arbitrary date-range pickers, terminate-session or any mutating Tautulli command, real-time websockets (10s polling is sufficient), and a dedicated media detail page (the modal covers it).

## Notes for the implementation plan

- Verify exact Tautulli field names for `get_home_stats` / `get_plays_by_date` / `get_libraries` against a live instance or the Tautulli API docs while writing the normalizers (the shapes above are the contract; field-name lookups are an implementation detail).
- The shared-partial extraction (Component 4) touches the existing `_plex_activity.html.twig` and `dashboard/index.html.twig` — keep the dashboard widget behaving identically (tabs, poll, modal) after the refactor.
