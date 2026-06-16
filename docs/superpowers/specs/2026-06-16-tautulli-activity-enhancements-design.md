# Tautulli Activity Page — Enhancements Design Spec

**Date:** 2026-06-16
**Status:** Approved (brainstorming) — ready for implementation plan
**Related:** extends the shipped Tautulli "Plex Activity" page (`docs/superpowers/specs/2026-06-13-tautulli-activity-page-design.md`). Builds on `TautulliClient`, the `TautulliController` fragment endpoints, the shared `_plex_session_card.html.twig`, and the existing info modal.

---

## Goal

Add three focused enhancements to the existing Tautulli activity page:

1. **Stream summary strip** — an at-a-glance live overview above Now Playing (session count, Direct Play / Direct Stream / Transcode breakdown, total/LAN/WAN bandwidth).
2. **Recently Added** — a new section listing the latest items added to Plex, each opening the info modal.
3. **Live-card polish** — HDR/SDR badge and human-readable transcode detail (codec transitions) on every stream card.

These reuse the existing architecture (server-rendered fragments, the image proxy, the delegated modal handler) and the existing security model (server-side API key, strict allow-list normalizers, fail-open endpoints).

## Decisions (from brainstorming)

| Decision | Choice |
| --- | --- |
| Scope | Summary strip + Recently Added + card polish (HDR/SDR + transcode reasons) |
| Recently Added | **Mixed** list, **latest 10**, newest first, **clickable** to the info modal, **no timestamps** |
| Summary strip placement | **Tautulli page only** (above Now Playing); the compact dashboard widget is unchanged |
| Transcode "reason" representation | **Codec transitions** (e.g. `Video: HEVC → H.264`), since Tautulli exposes no single prose reason field |
| Cut from scope | Server CPU, per-user/device bandwidth history, Problem Clients |

---

## Component A — Stream summary strip

**Files:** `symfony/src/Controller/TautulliController.php` (now-playing action), `symfony/templates/tautulli/_now_playing.html.twig`, i18n.

**No new data work.** `TautulliClient::getActivitySummary()` already returns `streamCount`, `directPlayCount`, `directStreamCount`, `transcodeCount`, and the `bandwidth` block (`totalMbps`, `lanMbps`, `wanMbps`). The `app_tautulli_api_now_playing` action already calls it.

- The `_now_playing` fragment renders a full-width strip **above** the session cards from the summary fields it already receives:
  - **Streams:** total `streamCount`.
  - **Decision breakdown:** Direct Play / Direct Stream / Transcode counts (reuse the same color treatment as the per-card decision badges — green / azure / orange).
  - **Bandwidth:** total Mbps, with LAN/WAN split shown when non-zero.
- Because it lives in `_now_playing`, it refreshes on the existing **10s poll** with no extra request.
- **Empty/idle state:** when `streamCount == 0`, the strip shows "No active streams" (existing empty-state wording) and the breakdown/bandwidth collapse — no zero-noise.
- The strip is **scoped to the Tautulli page only.** The dashboard widget (`_plex_activity.html.twig`) is not touched.

## Component B — Recently Added

**Files:** `symfony/src/Service/Media/TautulliClient.php` (+ test), `symfony/src/Controller/TautulliController.php`, `symfony/templates/tautulli/index.html.twig`, new `symfony/templates/tautulli/_recently_added.html.twig`, i18n.

### `TautulliClient::getRecentlyAdded(int $count = 10): array`
Wraps `get_recently_added` (`count`=clamped, no `section_id` → all libraries). Returns:
```
{ items: [{ ratingKey, title, year, posterPath, mediaType, grandparentTitle }] }
```
- `count` clamped to a bounded range (1–50; default 10) before reaching Tautulli.
- `normalizeRecentlyAdded(array $data)`: iterate `recently_added`, allow-list only the six fields above. `title` from `title` (movie) and, for episodes, prefer the series label via `grandparentTitle` (`grandparent_title`) + episode `title`; `posterPath` via the existing `pickPoster()` (portrait art, episodes use `grandparent_thumb`). `mediaType` from `media_type`.
- **Forbidden everywhere (asserted in tests):** `section_id`, `library_name` ids, `guid`, `plex://`, file paths, IPs, usernames, emails, `added_at` raw host fields. (We intentionally omit timestamps per the design.)

### Endpoint
| Route | Name | Returns | Notes |
| --- | --- | --- | --- |
| `/tautulli/api/recently-added` | `app_tautulli_api_recently_added` | HTML fragment | GET, `ROLE_USER`, fail-open, loaded **once** like Libraries. Returns the clean empty state on any failure. |

Must stay **unguarded** (prefix `app_tautulli_api_*`) like the other fragment endpoints.

### Template & wiring
- `tautulli/_recently_added.html.twig` — a mixed list of the latest 10, newest first (Tautulli returns newest-first), each row a modal trigger via `data-plex-rating-key` (reusing the existing clickable-poster pattern; can reuse `_plex_history_row.html.twig` shape or a simpler poster tile — implementer's choice, must carry the trigger and use the image proxy).
- New stacked section in `tautulli/index.html.twig` (`data-tautulli-recently-added`), fetched once on load by the page IIFE, mirroring how Libraries hydrates.
- The existing document-level delegated modal handler catches the clicks automatically.

## Component C — Live-card polish

**Files:** `symfony/src/Service/Media/TautulliClient.php` (`normalizeSession`, + test), `symfony/templates/dashboard/_plex_session_card.html.twig`, i18n.

Because the card partial is **shared**, these improvements appear on both the Tautulli page and the dashboard widget — intended (consistency).

### Normalizer additions (`normalizeSession`)
Add allow-listed fields (all via `self::str`, no new sensitive surface):
- `dynamicRange` — from `stream_video_dynamic_range` (fall back to `video_dynamic_range`). Values like `HDR`, `SDR`, `Dolby Vision`.
- Source vs stream codecs for the transition labels:
  - `videoCodec` (`video_codec`), `streamVideoCodec` (`stream_video_codec`)
  - `audioCodec` (`audio_codec`), `streamAudioCodec` (`stream_audio_codec`)

### Card template
- **HDR/SDR badge:** when `dynamicRange` is present, add a badge alongside the existing quality/bandwidth badges (neutral styling; uppercase).
- **Transcode detail:** the existing `plex-decisions` block (only rendered when `transcodeDecision == 'transcode'`) is reworked to show **codec transitions**:
  - Video: `{{ videoCodec }} → {{ streamVideoCodec }}` when both differ and the video track transcodes; otherwise the decision word.
  - Audio: same pattern with audio codecs.
  - Subtitle: keep the existing decision word (no clean source/target codec pair).
  - Codec labels uppercased and lightly prettified (e.g. `hevc` → `HEVC`, `h264` → `H.264`) in the template/translation layer — no new server logic required beyond passing the raw codecs.
  - Falls back gracefully to the current decision-word display when a codec field is missing.

---

## Security & fail-open

- API key stays server-side; the browser sees only sanitized fragments and proxied image bytes.
- Every new/modified normalizer is strict allow-list. Forbidden everywhere: IPs, tokens, machine/server/section ids, file paths, `guid`/`plex://`, usernames, emails, `user_id`, avatars.
- The new `recently-added` endpoint fails open independently (clean empty state; never a 500). The summary strip inherits the now-playing action's existing fail-open behavior.
- `count` allow-listed/clamped (1–50) before reaching Tautulli.
- Reuses the existing `\d+` ratingKey route requirement and the image-proxy SSRF allow-list.

## Testing & rollout

- **Unit (PHPUnit, in-container):**
  - `normalizeRecentlyAdded` — field mapping (movie + episode shapes) and sanitization (forbidden keys absent; serialized output contains no `plex://`, `/data/`, username/email substrings).
  - `getRecentlyAdded` count clamping (out-of-range → 10).
  - `normalizeSession` — new `dynamicRange` + codec fields mapped, and still no forbidden keys.
- **Controller smoke (PHPUnit):** `recently-added` returns the neutral empty fragment when Tautulli is unconfigured and stays unguarded; mirror the existing fragment-endpoint tests.
- **i18n (en + fr):** Recently Added heading + empty state; summary-strip labels (Streams / Direct Play / Direct Stream / Transcode / Bandwidth / LAN / WAN); HDR/SDR is data-driven (no key) but the transcode-transition separator/labels get keys as needed.
- **Lint/CI:** `lint:twig`, `lint:yaml`, `make check` green on CI. (No local PHP/Docker on this box — these run on CI after push.)
- **CHANGELOG + `docs/FORK-CHANGES.md`:** record the three enhancements.
- **Manual (Unraid rebuild):** summary strip shows correct counts and collapses when idle; Recently Added lists latest 10 and each opens the modal; HDR/SDR badge appears; a transcoding stream shows codec transitions; everything fails open when Tautulli is down.

## Notes for the implementation plan

- Verify exact Tautulli field names for `get_recently_added` and the session dynamic-range/codec fields against a live instance or the Tautulli API docs while writing the normalizers (the shapes above are the contract; field-name lookups are an implementation detail). In particular confirm `stream_video_dynamic_range` vs `video_dynamic_range` and `stream_video_codec`/`stream_audio_codec` exist in the `get_activity` session payload.
- The card-template change touches the **shared** `_plex_session_card.html.twig` — confirm the dashboard widget still renders correctly (badges wrap cleanly in the narrower widget column).
- The summary strip is added to the existing `_now_playing` fragment — confirm the now-playing action already passes the full `getActivitySummary()` result (counts + bandwidth) to the template, not just the `sessions` list.
