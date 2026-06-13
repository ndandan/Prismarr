# Plex activity info modal — design

**Date:** 2026-06-13
**Status:** Approved (design), pending implementation plan
**Area:** Tautulli integration / dashboard "Current Plex activity" widget

## Summary

Make each session in the "Current Plex activity" widget clickable. Clicking a
session's poster or title opens an **in-app modal** showing the playing title's
metadata — synopsis & basics, ratings, people, and the now-playing technical
detail — pulled server-side from Tautulli's `get_metadata` command. The modal
stays inside Prismarr and matches the look of the existing films/series detail
modals. Works for both movies and TV episodes.

This builds directly on the existing Tautulli integration (`TautulliClient`,
`TautulliController`, `_plex_activity.html.twig`) and reuses its security model:
every call is server-side, the API key never reaches the browser, and the
response is reduced to an allow-listed, sanitized shape before it leaves the
server.

## Goals

- Click a session's poster/title → open a modal with that title's info.
- Show: **synopsis & basics** (summary, year, content rating, runtime, genres),
  **ratings** (audience and/or critic), **people** (directors, writers, top
  cast), and **now-playing technical detail** (resolution, video/audio codec,
  container, bitrate, player/device).
- Support both movies and TV episodes (episodes show the show title, season /
  episode, and episode summary, plus show-level genres/cast).
- Fail open: a disabled / unreachable / wrong-key Tautulli, or a missing
  rating_key, shows a clean "couldn't load" state rather than breaking the page.

## Non-goals (YAGNI)

- No episode/season lists, no play history, no actor headshots.
- No links back out to Plex or Tautulli's own UI.
- No write/mutating actions (consistent with the read-only integration).
- No caching layer beyond what already exists (can be added later if the
  `get_metadata` call proves slow in practice).

## Chosen approach

**Approach A — server-rendered modal fragment.** A new endpoint returns the
modal body as rendered HTML, built server-side from sanitized `get_metadata`.
The poster/title becomes a clickable trigger; a small JS handler fetches the
fragment and shows it in a shared modal shell.

Rationale: mirrors how the dashboard already injects server-rendered fragments
(`applyFragment`), keeps sanitization and templating server-side (identical
security model to the poster proxy we just shipped), is CSP-friendly (no inline
data or scripts), and inherits the app's Twig modal styling for free.

Rejected: a JSON endpoint with client-side rendering (Approach B) — duplicates
formatting/rendering logic in JS, more JS to maintain, and tends to drift from
the app's existing styling.

## Architecture

### Data layer — `TautulliClient`

1. **Keep `ratingKey` in the normalized session.** `normalizeSession()`
   currently drops `rating_key`; add it to the allow-list. It is a
   non-sensitive Plex library identifier (the `90882` in
   `info?rating_key=90882`) and is required to look the title up.

2. **`getMetadata(string $ratingKey): array`** — new public method, shaped like
   `getActivity()`:
   - Calls `GET {tautulli_url}/api/v2?apikey=…&cmd=get_metadata&rating_key=…`.
   - Reuses the existing request plumbing: SSRF guard (`urlBlockedReason`),
     circuit breaker (`ServiceHealthCache`), locked curl protocols/timeouts.
   - Fails open: returns a neutral shape with an `error` code
     (`null | 'unconfigured' | 'unreachable' | 'auth' | 'not_found'`) instead of
     throwing.

3. **`normalizeMetadata(array $data): array`** — new **pure, static** method
   (unit-testable like `normalizeActivity`). Allow-lists only the fields the
   modal shows. Fields dropped *by construction* (never read): `file`,
   `*_file`, library `section`/`*_path`, `*_token`, `machine_id`, IPs, raw
   payload.

   Normalized metadata shape (illustrative):
   ```
   {
     mediaType, title, grandparentTitle, year,
     season, episode,                # episodes only
     summary, tagline, contentRating, durationMs, durationLabel,
     genres: [string],
     ratings: { audience: ?float, critic: ?float },
     directors: [string], writers: [string], cast: [string],
     studio, releaseDate,
     media: { resolution, videoCodec, audioCodec, container, bitrateKbps }
   }
   ```
   `cast` capped (e.g. top 8). Strings trimmed; empty → null/omitted.
   The `media` block (resolution, codecs, container, bitrate) is the **source
   file's** info from `get_metadata`.

   **Now-playing block — field sourcing (resolves ambiguity):**
   - From `get_metadata` (`media` block above): source resolution, video codec,
     audio codec, container, bitrate.
   - From the **live session**: `player` and `device`. These are not in
     `get_metadata`, so the modal endpoint accepts them as optional,
     display-only query params (see HTTP layer). They are echoed through Twig
     autoescaping only (no injection risk) and are non-authoritative labels.
   - The live *transcode decision* (direct play / transcode) already shows as a
     badge on the widget card and is intentionally NOT duplicated in the modal.

### HTTP layer — `TautulliController`

- **`GET /tautulli/api/metadata/{ratingKey}`**
  - `#[IsGranted('ROLE_USER')]` (inherited from the controller).
  - `ratingKey` route requirement: digits only (`\d+`).
  - Optional query params `player` and `device` (display-only, for the
    now-playing block). Echoed through Twig autoescaping; never used in the
    upstream call or any logic.
  - Calls `getMetadata()` and renders `dashboard/_plex_metadata.html.twig`.
  - On the neutral/error shape, renders the modal's clean error state (HTTP 200
    with an error body, consistent with the activity endpoint) so the modal can
    show "couldn't load this title" rather than a broken dialog.

### View layer

- **`templates/dashboard/_plex_metadata.html.twig`** — the modal body: poster,
  title/year (or show title + SxxEyy for episodes), ratings, genres, synopsis,
  people (directors/writers/cast), and the now-playing technical block. Styled
  to match the existing films/series detail modal.

- **`templates/dashboard/_plex_activity.html.twig`** — wrap the poster + title
  in a trigger carrying `data-rating-key`, plus `data-player` / `data-device`
  for the now-playing block. Non-clickable when no rating_key is present.

- **Dashboard JS (`index.html.twig`)** — add a delegated click handler: read the
  trigger's data attributes, fetch
  `/tautulli/api/metadata/{ratingKey}?player=…&device=…` (params URL-encoded),
  inject the returned HTML into a shared modal shell, and show it
  (Tabler/Bootstrap modal). Same-origin fetch, no inline scripts (CSP-clean).
  Closing/disposing follows the existing modal pattern.

## Data flow

1. Widget renders sessions (existing). Each session's poster/title is a trigger
   with `data-rating-key`.
2. User clicks → JS reads `data-rating-key`/`data-player`/`data-device` and
   `fetch('/tautulli/api/metadata/<ratingKey>?player=…&device=…')`.
3. Controller → `TautulliClient::getMetadata()` → Tautulli `get_metadata`
   (server-side, API key server-only) → `normalizeMetadata()` (sanitized).
4. Controller renders `_plex_metadata.html.twig` → returns HTML.
5. JS injects HTML into the modal shell and shows it.

## Error handling

- Disabled / unconfigured / unreachable / auth / not_found → neutral shape with
  an `error` code → modal shows a clean message. Never a stack trace or secret.
- `getMetadata()` fails open (try/catch at the controller mirrors
  `apiActivity`).
- Circuit breaker prevents a downed Tautulli from costing a timeout per click.

## Security

- API key stays server-side; the browser only ever receives rendered HTML.
- `rating_key` constrained to digits at the route; no free-form input reaches
  the upstream call.
- `normalizeMetadata` is an allow-list: file paths, section/library paths,
  tokens, machine ids and IPs are never copied into the output.
- Same-origin fragment; existing CSP (`default-src 'self'`) covers it with no
  changes.

## Testing

- Extend `tests/Service/Media/TautulliClientTest.php`:
  - `normalizeMetadata` maps the core fields for a **movie** fixture.
  - `normalizeMetadata` maps show/season/episode fields for an **episode**
    fixture (grandparentTitle, season, episode, episode summary).
  - `normalizeMetadata` **strips** sensitive fields (file path, section path,
    token) — the security-critical assertion, mirroring the existing
    `testStripsPrivateFields`.
  - Cast list is capped.
- Twig lint covers the new template (CI `make check`).
- CI (`make check`: PHP lint + Twig lint + PHPUnit) is the gate; the GHCR
  workflow republishes `:latest` on merge to `main` for Unraid testing.

## Affected files

- `symfony/src/Service/Media/TautulliClient.php` (modify: session allow-list +
  `getMetadata` + `normalizeMetadata`)
- `symfony/src/Controller/TautulliController.php` (modify: metadata route)
- `symfony/templates/dashboard/_plex_metadata.html.twig` (new)
- `symfony/templates/dashboard/_plex_activity.html.twig` (modify: trigger)
- `symfony/templates/dashboard/index.html.twig` (modify: modal shell + JS)
- `symfony/tests/Service/Media/TautulliClientTest.php` (modify: tests)
- `CHANGELOG.md` (fold into the existing unreleased Tautulli entry)
