# Plex activity: info modal, live pill & recently-watched — design

**Date:** 2026-06-13
**Status:** Approved (design), pending implementation plan
**Area:** Tautulli integration / dashboard "Current Plex activity" widget + sidebar

## Summary

Three additions to the existing Tautulli integration, sharing one set of
server-side plumbing and one security model:

1. **Info modal** — clicking a title (a now-playing session *or* a
   recently-watched row) opens an in-app modal with that title's metadata
   (synopsis & basics, ratings, people, now-playing technical detail) from
   Tautulli's `get_metadata`.
2. **Live streaming pill** — a small indicator under the Prismarr logo in the
   sidebar that appears only when something is streaming (`streamCount > 0`),
   giving at-a-glance awareness, and links to the dashboard's Plex section.
3. **Recently-watched tab** — the "Current Plex activity" widget becomes a
   tabbed card: **Now playing** / **Recently watched**, the latter backed by
   Tautulli's `get_history`.

All three reuse the integration's security guarantees: every call is
server-side, the API key never reaches the browser, responses are reduced to an
allow-listed sanitized shape (no IPs, tokens, machine ids, file/section paths or
raw payload), and everything fails open.

## Goals

- Click a now-playing session **or** a recently-watched row → open one shared
  modal with that title's info.
- Modal shows: **synopsis & basics** (summary, year, content rating, runtime,
  genres), **ratings** (audience/critic), **people** (directors, writers, top
  cast), **now-playing technical detail** (resolution, video/audio codec,
  container, bitrate, player/device).
- Sidebar pill appears only while streaming and links to the dashboard Plex
  section.
- Widget has Now playing / Recently watched tabs; **defaults to Recently watched
  when nothing is streaming**, otherwise Now playing.
- Support movies and TV episodes throughout.
- Fail open everywhere: disabled / unreachable / wrong-key Tautulli, or a
  missing rating_key, shows a clean state — never a stack trace or a secret.

## Non-goals (YAGNI)

- No dedicated full history page, no server-side history pagination/filters (the
  tab shows a fixed small recent list).
- No episode/season lists, no play-history charts, no actor headshots.
- No write/mutating actions (the integration stays read-only).
- No links back out to Plex or Tautulli's own UI.

## Chosen approach

**Server-rendered fragments + lightweight client glue.** Endpoints return
rendered HTML built from sanitized Tautulli data; small delegated JS handlers
fetch fragments and drive a shared modal / the tab toggle / the pill. This
mirrors the dashboard's existing `applyFragment` pattern, keeps sanitization and
templating server-side, and is CSP-clean (no inline data or scripts).

Rejected: JSON endpoints with client-side rendering — duplicates
formatting/rendering logic in JS, more JS to maintain, drifts from the app's
Twig styling.

---

## Feature 1 — Info modal

### Data layer — `TautulliClient`

- **Keep `ratingKey` in the normalized session.** `normalizeSession()` currently
  drops `rating_key`; add it to the allow-list (a non-sensitive Plex library id,
  the `90882` in `info?rating_key=90882`). Required to look the title up.
- **`getMetadata(string $ratingKey): array`** — shaped like `getActivity()`:
  calls `cmd=get_metadata&rating_key=…`, reuses the SSRF guard
  (`urlBlockedReason`), circuit breaker (`ServiceHealthCache`), and locked
  curl protocols/timeouts. Fails open: neutral shape with an `error` code
  (`null | 'unconfigured' | 'unreachable' | 'auth' | 'not_found'`).
- **`normalizeMetadata(array $data): array`** — pure, static, unit-testable.
  Allow-lists only displayed fields. Dropped by construction: `file`/`*_file`,
  library `section`/`*_path`, `*_token`, `machine_id`, IPs, raw payload.

  Shape (illustrative):
  ```
  {
    mediaType, title, grandparentTitle, year,
    season, episode,                # episodes only
    summary, tagline, contentRating, durationMs, durationLabel,
    genres: [string],
    ratings: { audience: ?float, critic: ?float },
    directors: [string], writers: [string], cast: [string],   # cast capped at 8
    studio, releaseDate,
    media: { resolution, videoCodec, audioCodec, container, bitrateKbps }  # source file
  }
  ```

  **Now-playing block — field sourcing (resolves ambiguity):**
  - From `get_metadata` (`media` block): source resolution, video/audio codec,
    container, bitrate.
  - From the **live session**: `player`, `device` — not in `get_metadata`, so
    the modal endpoint accepts them as optional, display-only query params,
    echoed through Twig autoescaping only.
  - The live *transcode decision* badge stays on the widget card and is NOT
    duplicated in the modal.
  - **Opened from a recently-watched row:** there is no live stream, so no
    `player`/`device` is passed. The now-playing block then shows only the
    source `media` info (resolution/codecs/container/bitrate); the player/device
    line is omitted. The modal template treats player/device as optional.

### HTTP layer — `TautulliController`

- **`GET /tautulli/api/metadata/{ratingKey}`** — `ROLE_USER`; `ratingKey`
  requirement `\d+`; optional `player`/`device` display-only query params.
  Renders `dashboard/_plex_metadata.html.twig` from `getMetadata()`. On the
  neutral/error shape, renders the modal's clean error state (HTTP 200 with an
  error body, consistent with the activity endpoint).

### View layer

- **`templates/dashboard/_plex_metadata.html.twig`** (new) — modal body: poster,
  title/year (or show title + SxxEyy for episodes), ratings, genres, synopsis,
  people, now-playing technical block. Styled to match the films/series detail
  modal.
- Triggers carry `data-rating-key`, `data-player`, `data-device` (see the
  widget feature).
- **Shared modal shell + JS** in `index.html.twig` — a delegated click handler
  reads the trigger's data attributes, fetches
  `/tautulli/api/metadata/{ratingKey}?player=…&device=…` (params URL-encoded),
  injects the HTML into the shell, shows it (Tabler/Bootstrap modal). Same-origin
  fetch, no inline scripts.

---

## Feature 2 — Live streaming pill (sidebar)

### Placement & markup

- In `templates/base.html.twig`, directly under `.navbar-brand` (the logo,
  ~line 688). Rendered only when Tautulli is configured/visible (reuse the
  existing sidebar service-visibility check). Mirrors the existing
  `.sidebar-dl-badge` background-hydration pattern.
- Hidden by default (`hidden`/zero state). Pill content: a play glyph + count
  (`▶ N`). Wrapped in a link to `app_dashboard` anchored to the Plex widget.

### Hydration

- A small background poller (~30s, paused while the tab is hidden) fetches the
  **existing** `/tautulli/api/activity` endpoint (already returns `streamCount`)
  and toggles the pill visible with the count when `streamCount > 0`, hidden
  otherwise. Fails open (stays hidden on error).
- Collapses gracefully with `sidebar-collapsed` (label hides like other sidebar
  text; a compact dot/badge remains).

### Anchor

- Add an `id` (e.g. `id="plex-activity"`) to the widget card in
  `index.html.twig` so the pill link can deep-link to it
  (`{{ path('app_dashboard') }}#plex-activity`).

---

## Feature 3 — Recently-watched tab

### Data layer — `TautulliClient`

- **`getHistory(int $length = 8): array`** — calls
  `cmd=get_history&length=8&order_column=date&order_dir=desc`. Same request
  plumbing / fail-open behavior as `getActivity()`.
- **`normalizeHistory(array $data): array`** — pure, static, unit-testable.
  Allow-lists per row: `ratingKey`, title (full_title → title), grandparentTitle,
  year, mediaType, poster path (thumb / grandparent_thumb via the existing
  `pickPoster` logic), user display name (friendly_name; never the Plex login /
  email), watched timestamp → relative "how long ago", watched percent/status.
  Dropped: IPs, tokens, machine ids, file/section paths, raw payload.
- **Cached server-side ~60s** (reuse the Symfony cache pool, like the dashboard
  widget cache) so the 10s now-playing poll doesn't hammer `get_history`.

### View layer

- The "Current Plex activity" card becomes tabbed: **Now playing** /
  **Recently watched**. Both panes render server-side in the single widget
  fragment (`_plex_activity.html.twig`), so one fetch fills both.
- **Recently-watched pane** — compact list: poster thumb, title (show title +
  SxxEyy for episodes), who watched, relative time. Each row is a trigger
  (`data-rating-key`) opening the shared info modal. Empty state when no history.
- **Default tab:** Recently watched when `streamCount == 0`, else Now playing.
  The server-rendered fragment sets the initial active tab; the client toggle
  switches panes (pure CSS/class swap, no refetch).

### Refresh behavior

- The existing 10s widget auto-refresh re-renders the fragment. The refresh JS
  **preserves the user's active tab** across re-injection (remember the active
  tab key, re-apply after `applyFragment`), so reading history isn't interrupted.

### Controller

- `DashboardController::widgetPlex()` passes both `activity` (existing) and
  `history` (new, from cached `getHistory()`) plus the computed default tab to
  the template. No new route needed (history rides the existing widget
  fragment).

---

## Data flow

**Modal:** click trigger → JS reads `data-rating-key`/`data-player`/`data-device`
→ `fetch('/tautulli/api/metadata/<id>?player=…&device=…')` → controller →
`getMetadata()` → `normalizeMetadata()` → render `_plex_metadata.html.twig` →
inject into modal shell → show.

**Pill:** sidebar poller → `fetch('/tautulli/api/activity')` → read `streamCount`
→ toggle pill visibility + count.

**Recently watched:** widget fragment render → `getActivity()` + cached
`getHistory()` → both panes rendered → 10s refresh re-renders, active tab
preserved.

## Error handling

- Every upstream path fails open to a neutral shape with an `error` code; the UI
  shows a clean message (modal, pane, or hidden pill) — never a stack trace or a
  secret.
- Circuit breaker (`ServiceHealthCache`) prevents a downed Tautulli from costing
  a timeout per click / poll.
- The sidebar pill never breaks page render: on any error it simply stays hidden.

## Security

- API key stays server-side; the browser only ever receives rendered HTML and
  the already-sanitized `streamCount`.
- `rating_key` constrained to digits at the route; `player`/`device` are
  display-only and Twig-escaped.
- `normalizeMetadata` and `normalizeHistory` are allow-lists: file/section
  paths, tokens, machine ids, IPs, Plex login/email are never copied into output.
- All fragments are same-origin; existing CSP (`default-src 'self'`,
  `img-src 'self'`) covers them with no changes.

## Testing

Extend `tests/Service/Media/TautulliClientTest.php` (pure-logic, no network):

- `normalizeMetadata`: maps core fields for a **movie** fixture; maps
  show/season/episode fields for an **episode** fixture; **strips** sensitive
  fields (file path, section path, token); cast list capped at 8.
- `normalizeHistory`: maps rows for movie + episode fixtures; uses
  friendly_name (never username/email); episode poster prefers grandparent
  thumb; **strips** sensitive fields.
- Twig lint covers the new/changed templates (CI `make check`).
- CI (`make check`: PHP lint + Twig lint + PHPUnit) is the gate; the GHCR
  workflow republishes `:latest` on merge to `main` for Unraid testing.

## Affected files

- `symfony/src/Service/Media/TautulliClient.php` — session allow-list (+ratingKey),
  `getMetadata`/`normalizeMetadata`, `getHistory`/`normalizeHistory`.
- `symfony/src/Controller/TautulliController.php` — metadata route.
- `symfony/src/Controller/DashboardController.php` — pass history + default tab to
  the widget fragment; short cache for `getHistory`.
- `symfony/templates/dashboard/_plex_metadata.html.twig` — new (modal body).
- `symfony/templates/dashboard/_plex_activity.html.twig` — tabs, recently-watched
  pane, modal triggers on rows.
- `symfony/templates/dashboard/index.html.twig` — modal shell, modal-open JS,
  tab toggle + active-tab-preserving refresh, widget anchor id.
- `symfony/templates/base.html.twig` — sidebar pill markup + hydration JS.
- `symfony/translations/messages+intl-icu.en.yaml` / `.fr.yaml` — new strings
  (tab labels, modal field labels, pill, recently-watched relative time, empty
  states).
- `symfony/tests/Service/Media/TautulliClientTest.php` — tests.
- `CHANGELOG.md` — fold into the existing unreleased Tautulli entry.

## Build order (suggested)

1. `TautulliClient`: keep `ratingKey`; add `getMetadata`/`normalizeMetadata` +
   tests.
2. Metadata route + `_plex_metadata.html.twig` + shared modal shell/JS; wire
   now-playing session triggers. (Feature 1 shippable here.)
3. `getHistory`/`normalizeHistory` + tests; cache in `DashboardController`.
4. Tabs + recently-watched pane + default-tab logic + active-tab-preserving
   refresh; history rows reuse the modal. (Feature 3.)
5. Sidebar pill markup + hydration + widget anchor. (Feature 2.)
6. Translations + CHANGELOG.
