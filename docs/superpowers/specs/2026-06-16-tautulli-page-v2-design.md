# Tautulli Activity Page v2 — Declutter + Graphs Design Spec

**Date:** 2026-06-16
**Status:** Approved (brainstorming) — ready for implementation plan
**Related:** revises the shipped Tautulli "Plex Activity" page (`docs/superpowers/specs/2026-06-13-tautulli-activity-page-design.md` + `2026-06-16-tautulli-activity-enhancements-design.md`). Reuses `TautulliClient`, the `{categories, series}` chart contract, the existing 7/30/90-day range toggle, and the self-hosted Chart.js.

---

## Goal

Tighten the page's information density and add the genuinely useful graphs native Tautulli has that we lack — **server-load patterns** and **transcode / "problem client" insight** — while keeping our clean, stacked-card aesthetic. Concretely:

1. **Declutter:** remove the Recently Added section (the *arr apps cover "recently added"), make History a dense full-width grid, slim Libraries to a single-column list, and drop any aggregate "Total" series from charts.
2. **Add three graphs** (all reuse the existing chart data contract + range toggle): a Media Type ⇄ Stream Type toggle on the plays chart, an activity-patterns pair (by hour of day / by day of week), and a problem-clients chart (platform × stream type).

## Decisions (from brainstorming)

| Decision | Choice |
| --- | --- |
| Recently Added | **Full removal** (section, JS, endpoint, client method + normalizer, tests, i18n) |
| History layout | **Full-width responsive grid** (~2 cells/line tablet, 3 wide): poster + title (year) + "user · when"; **no progress bar**; keep "Load more" |
| Libraries layout | **Slim single-column list** (one thin row per library: icon + name + counts) |
| Charts "Total" | **Dropped** — per-type stacked breakdown only |
| New graphs | Stream-type toggle on plays chart; hour-of-day + day-of-week; platform × stream type |
| Graph organization | **Stacked cards** (not native's tabs) |
| Chart type | **Stacked bar** for all (consistent with existing plays chart) |

---

## Component 1 — Remove Recently Added (revert)

Delete everything added for Recently Added in the prior enhancement round:

- **Delete** `symfony/templates/tautulli/_recently_added.html.twig`.
- **`TautulliController`:** remove `apiRecentlyAdded()` and its `#[Route('/api/recently-added', ...)]`; revert the class docblock's command list to drop `get_recently_added`.
- **`TautulliClient`:** remove `getRecentlyAdded()` and `normalizeRecentlyAdded()`.
- **`TautulliClientTest`:** remove `recentlyAddedFixture()`, `testNormalizeRecentlyAddedMapsFields`, `testNormalizeRecentlyAddedStripsPrivateFields`, `testNormalizeRecentlyAddedEmpty`.
- **`TautulliControllerTest`:** remove `testRecentlyAddedFragmentRendersEmptyStateWhenUnconfigured`.
- **`tautulli/index.html.twig`:** remove the Recently Added card + the `refreshRecentlyAdded()` function and its hydration call.
- **i18n (en + fr):** remove `tautulli.section.recently_added` and the `tautulli.recently_added` block.

No upstream code is touched — this is all fork-only code we added, so removal is clean (consistent with the fork-parity rule: only remove code *we* added).

## Component 2 — History as a dense grid

**Files:** `symfony/templates/tautulli/_history_rows.html.twig`, `symfony/templates/tautulli/index.html.twig`, page JS.

- The page's history fragment (`_history_rows.html.twig`, rendered by `/tautulli/api/history`) currently renders one full-width row per item via the shared `dashboard/_plex_history_row.html.twig`. Change it to render a **responsive grid of compact cells** instead. The shared `_plex_history_row.html.twig` stays unchanged (the dashboard widget keeps using it).
- **Cell markup** (new, inline in `_history_rows.html.twig` or a small `tautulli/_history_cell.html.twig`): a Bootstrap/Tabler grid column (`col-6 col-md-4` → 2 on tablet, 3 on wide), each cell carrying the modal trigger (`data-plex-rating-key`), a small poster (reuse `.plex-poster-sm` already defined in `index.html.twig`), the title (year) truncated, and a `user · {{ watchedAt|relative_date }}` line. **No progress bar.** Cells use a wrapper class **`plex-hist-cell`**.
- **`index.html.twig` layout:** History moves out of the shared `row row-cards` two-column block into its **own full-width card**. The grid container (`data-tautulli-history`) holds a `row row-cards` (or `row g-2`) into which cells are injected. "Load more" button stays.
- **JS "Load more" count fix:** `loadHistory()` currently counts appended rows with `html.match(/class="plex-session/g)`. Update the regex to match the new cell class (`/class="[^"]*plex-hist-cell/g`) so pagination still stops correctly when a page returns fewer than `length` cells. The empty-state fallback string stays.

## Component 3 — Libraries as a slim single-column list

**File:** `symfony/templates/tautulli/_libraries.html.twig`, `index.html.twig`.

- Replace the current `row row-cards` card grid with a **single-column list**: one thin row per library — small type icon + name + the existing `items` / `episodes` counts on one line. Keep the empty state (`tautulli.libraries.empty`).
- In `index.html.twig`, Libraries becomes its own full-width section below History (the old shared History/Libraries `row` is dissolved). It stays compact because each library is a one-line row.

## Component 4 — New graphs (data layer)

**File:** `symfony/src/Service/Media/TautulliClient.php` (+ tests).

All four new/extended chart feeds return the **same `{categories, series}` contract** as `getPlaysByDate` and reuse the existing pure `normalizePlaysByDate()` transform (so one tested normalizer covers them all). Each new method clamps the range via `clampRange()` and fails open to the empty shape.

| Method | Tautulli command | categories | series |
| --- | --- | --- | --- |
| `getPlaysByStreamType(int $days)` | `get_plays_by_stream_type` | dates | Direct Play / Direct Stream / Transcode |
| `getPlaysByHourOfDay(int $days)` | `get_plays_by_hourofday` | hours `0..23` | media types (TV/Movies/Music) |
| `getPlaysByDayOfWeek(int $days)` | `get_plays_by_dayofweek` | weekdays | media types |
| `getStreamTypeByPlatform(int $days)` | `get_stream_type_by_top_10_platforms` | platform names | Direct Play / Direct Stream / Transcode |

**Drop "Total" series:** extend `normalizePlaysByDate()` to skip any series whose `name` is `Total` (case-insensitive). This satisfies "remove the Total from the chart" across **every** chart (including the existing plays chart) in one place. Unit-tested.

## Component 5 — New graphs (endpoints)

**File:** `symfony/src/Controller/TautulliController.php`.

All GET, `ROLE_USER`, fail-open to JSON `{categories:[],series:[]}`, mirroring the existing `apiPlays`. Names stay in the unguarded `app_tautulli_api_*` family.

| Route | Name | Feed |
| --- | --- | --- |
| `/tautulli/api/plays?range=&mode=media\|stream` | `app_tautulli_api_plays` *(extend)* | `mode=stream` → `getPlaysByStreamType`, else `getPlaysByDate` (default `media`) |
| `/tautulli/api/activity-hour?range=` | `app_tautulli_api_activity_hour` | `getPlaysByHourOfDay` |
| `/tautulli/api/activity-dow?range=` | `app_tautulli_api_activity_dow` | `getPlaysByDayOfWeek` |
| `/tautulli/api/clients-stream-type?range=` | `app_tautulli_api_clients_stream_type` | `getStreamTypeByPlatform` |

`mode` is allow-listed to `{media, stream}` (anything else → `media`) before use.

## Component 6 — New graphs (templates & JS)

**File:** `symfony/templates/tautulli/index.html.twig`.

**New page flow (top → bottom):**
1. Now Playing (+ summary strip) — unchanged
2. Statistics tiles — unchanged
3. **Plays over time** — add a small **Media Type ⇄ Stream Type** button group on the card header (`data-tautulli-plays-mode`, buttons `data-mode="media|stream"`, default `media`). Toggling refetches `/api/plays?range=N&mode=M` and redraws.
4. **Activity patterns** — a two-up row (`col-lg-6` each): canvas `tautulli-activity-hour` + canvas `tautulli-activity-dow`, each with its own empty state.
5. **Problem clients** — full-width card, canvas `tautulli-clients-stream-type`, empty state.
6. **History** — full-width grid (Component 2)
7. **Libraries** — slim single column (Component 3)

**JS generalization:** refactor the current single-purpose `refreshPlays()` into a small reusable helper, e.g. `drawStackedBar({canvasId, url, wrapSel, emptySel})` that fetches JSON, toggles the wrap/empty visibility, and destroys+recreates the Chart.js instance (kept in a `charts[canvasId]` registry). The four charts (plays, hour, dow, clients) all call it. Behavior preserved: `maintainAspectRatio:false`, stacked x/y, integer ticks, legend shown when >1 series.

- **Range toggle (`data-tautulli-range`)** now refreshes stats + all four charts.
- **Mode toggle (`data-tautulli-plays-mode`)** refreshes only the plays chart (passing the current range + selected mode).
- **Series colors:** extend `SERIES_COLORS` with stream-type names → badge palette: `Direct Play` green (`#22c55e`), `Direct Stream` azure (`#0ea5e9`), `Transcode` orange (`#f59e0b`); keep the existing TV/Movies/Music entries. Unknown series fall back to the existing grey.

## Component 7 — i18n, security, testing, docs

- **i18n (en + fr):** add `tautulli.section.activity` (Activity patterns), `tautulli.section.activity_hour` (Plays by hour of day), `tautulli.section.activity_dow` (Plays by day of week), `tautulli.section.clients` (Plays by platform & stream type), `tautulli.plays.mode.media` / `tautulli.plays.mode.stream`, and per-chart empty states (reuse `tautulli.plays.empty` where it fits). Remove the recently-added keys (Component 1).
- **Security / fail-open:** unchanged model — every new endpoint fails open to the neutral JSON shape; `range` clamped to {7,30,90}; `mode` allow-listed; no new sensitive fields (these commands return aggregate counts only — no IPs/tokens/usernames/ids). The platform/weekday/hour labels are non-sensitive aggregates.
- **Testing (PHPUnit, in-container on CI — no local PHP):**
  - `normalizePlaysByDate` drops a `Total` series (new assertion) and still maps `{categories, series}` for the platform×stream-type and hour/dow shapes.
  - Controller smoke: each new JSON endpoint returns `{categories:[],series:[]}` when Tautulli is unconfigured (mirror the existing `apiPlays` test); the `mode` param is honored/clamped.
  - Remove the Recently Added tests (Component 1).
- **Lint/CI:** `lint:twig`, `lint:yaml`, `make check` green on CI after push.
- **Docs:** update the `[Unreleased]` `CHANGELOG.md` entry — since Recently Added never shipped in a release, edit the unreleased text to drop the Recently Added mention and describe the v2 graphs/declutter instead. Update `docs/FORK-CHANGES.md` likewise.
- **Manual (Unraid rebuild):** Recently Added gone; History is a dense grid that paginates and stops; Libraries is a slim list; the plays chart toggles Media ⇄ Stream; hour/day-of-week and problem-clients charts draw and respond to the range toggle; no "Total" series anywhere; every chart fails open to its empty state when Tautulli is down.

---

## Out of scope (YAGNI / deferred)

- Source vs stream **resolution** charts and the **12-month** monthly-totals chart (the "Extras" bundle — deferred; the monthly chart also needs a separate time axis from the 7/30/90-day toggle).
- Native's per-top-10 **users/platforms** count charts (we already show those as Statistics tiles).
- Concurrent-stream-count graph, click-through "items played on this date" drill-downs, CSV/export.

## Notes for the implementation plan

- Verify the exact Tautulli field/series names for `get_plays_by_stream_type`, `get_plays_by_hourofday`, `get_plays_by_dayofweek`, and `get_stream_type_by_top_10_platforms` against the API while writing the methods — all are documented to return the `series_data` `{categories, series}` envelope `normalizePlaysByDate` already parses; series *names* (e.g. "Direct Play") drive the color map.
- The History grid change touches the page-only `_history_rows.html.twig`; confirm the dashboard widget (which uses `dashboard/_plex_history_row.html.twig` directly) is unaffected, and update the "Load more" count regex to the new cell class.
- Keep the `drawStackedBar` refactor faithful to the current chart options so the existing plays chart looks identical in `mode=media`.
