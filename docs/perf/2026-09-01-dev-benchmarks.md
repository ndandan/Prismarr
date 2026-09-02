# `:beta` benchmarks vs the 2026-09-01 baseline — perf/bazarr-responsiveness

Control group: [2026-09-01-live-baseline-latest.md](2026-09-01-live-baseline-latest.md) (immutable —
do not edit it; this document is the comparison, not a replacement).

Method: identical harness (§Method there — server-side TTFB via `PerformanceResourceTiming`,
`cache:'no-store'` + a cache-buster query param, 5 sequential samples per route, median/p95/max
reported, never averages). **Nothing merges until the `:beta` table below is filled by the lead from
a live run and every acceptance row passes.**

## F3 — dev micro-benchmark (already measured, container-only)

Global search's per-request title normalization was isolated and benchmarked standalone (no kernel,
no network, no live library) in the `prismarr-qltest` container. Full method, dataset and caveats:
`.superpowers/perf-2026-09-01/dev-bench-search.md`. Summary:

| query | OLD median | OLD p95 | NEW median | NEW p95 | speed-up (median) |
|---|---|---|---|---|---|
| `zzzzqq` (0 results) | 783.6 ms | 793.8 ms | 0.20 ms | 0.21 ms | **~3,905×** |
| `matr` (few, 8) | 781.6 ms | 799.5 ms | 0.21 ms | 0.23 ms | **~3,662×** |
| `ma` (many, capped 12 of 1,675) | 3,290.4 ms | 3,323.5 ms | 3.00 ms | 3.61 ms | **~1,095×** |
| `the` (match-heavy, 1,793) | 3,325.5 ms | 3,370.2 ms | 3.10 ms | 3.44 ms | **~1,072×** |

**NEW index build** (pre-normalizing all 3,700 synthetic rows' 3 fields once): **42.3 ms** median of
5 runs — amortized across every search hitting the 60 s cache window, not paid per request.

Caveats (see the source file for the full text): synthetic titles, not the live library — real
accent/length distribution will shift absolute numbers, though the ICU-call count driving the
OLD/NEW gap is dictated by row count and algorithm shape, not title content. Container CPU (Docker
Desktop / WSL2) differs from the Unraid production host — treat the millisecond values as
directional; the three-to-four-orders-of-magnitude relative speed-up is the load-bearing result. The
live baseline's own `?q=mat` number (1.6 s warm / ~5 s match-heavy) also includes the `prismarr_search_*`
index-cache logic, Bazarr subtitle-status lookups and full HTTP/Symfony overhead that this isolated
benchmark does not measure — the `:beta` table below is what closes that gap.

## F1 / F2 / F4 — dev evidence (functional, not timed)

There is no representatively-sized Bazarr install or 3.4k-row Radarr/Sonarr library in the dev/test
environment, so F1 (Bazarr badges/index), F2 (Bazarr tab) and F4 (library cache) have no dev-container
timing number — the live `:beta` run below is what measures them. What Task-level testing *does* pin,
deterministically, is that the request path makes **zero** full-list Bazarr/Radarr/Sonarr calls on a
hard miss — the change that makes the live wall-clock improvement possible in the first place:

- `BazarrSubtitleIndexSwrTest` — a hard-miss read never calls the injected `BazarrClient`; it returns
  `pending` and dispatches a `RefreshCacheKey` message instead of fetching inline.
- `BazarrSubtitleIndexTest::testTheBadgeReadPathContainsNoClientCall` — the badge read path
  (`movieStatus()`/`seriesStatus()`) is asserted to never construct or call a client, on both a warm
  and a cold map, closing the exact N+1 shape the architecture review's C1 defect described.
- `MediaLibraryCacheTest` (stale and soft-hit cases) — a request inside the soft TTL returns the
  cached value with no client call; only a hard miss computes inline (and that computation is
  coalesced across concurrent requests via `LockRegistry`, not re-run per caller).
- `BazarrFrameRenderTest` — `/bazarr`, `/bazarr/movies`, `/bazarr/series` render their view from the
  D2 caches only; the Turbo-Frame branch and the full-page branch are both exercised without a live
  Bazarr fetch.

## `:beta` timing table — TO BE FILLED

| Route | Baseline cold / warm | `:beta` cold / warm | Δ | Target | Pass? |
|---|---|---|---|---|---|
| Films `/medias/radarr-1/films?status=all&sort=title-asc` | 11,515 / 318 ms | | | ≤3.5 s worst case once per 10 min; ≈320 ms otherwise; Bazarr never in the path | |
| Movie quick-look `quicklook/movie/radarr-1/8172` | 11,488 / 232 ms | | | <1 s cold (one per-id call), ≈230 ms warm | |
| Global search `?q=mat` | 9,780 / 1,613 ms | | | tens of ms warm | |
| Global search `?q=the` (series slug) | — / 4,980 ms | | | tens of ms warm | |
| Global search `?q=zzzzqq` (0 results) | — / 1,613 ms | | | tens of ms warm | |
| Bazarr landing `/bazarr` | 10,170 / 7,019 ms | | | shell <50 ms, view tens of ms warm | |
| Bazarr Movies `/bazarr/movies` | 4,171 / 7,494 ms | | | same | |
| Bazarr Series `/bazarr/series` | 664 / 5 ms | | | no regression | |
| Dashboard `widget/recent` | 3,766 / 240 ms | | | shares the library entry; cold paid once per hard window | |
| Discover `/decouverte` (control, Bazarr-free) | 4,146 / 3,516 ms | | | unchanged — this is the control that validates the run | |
| Series `/medias/sonarr-1/series` | 1,172 / 66 ms | | | no regression | |

## Acceptance rules

1. **No warm route regresses beyond noise.** Discover is the control: if it moved, the run is invalid.
2. **Bazarr call count per 60 s of browsing must not rise.** Baseline steady state is 1 × `/movies`,
   1 × `/series`, plus one `/system/status` per health sweep. Target: ≤1 `/movies`, ≤1 `/series`,
   ≤1 `/badges` per 60 s regardless of how many Bazarr views are opened, plus pings.
3. **Worker memory plateaus.** `docker stats prismarr` sampled over ≥1 h of normal traffic with
   `PRISMARR_WORKER=1`, compared against the recorded 135–160 MiB/worker baseline. A plateau, not a
   climb. Check the `messenger-worker` process separately — it now decodes ~5.4k rows per refresh.
4. **The consumer is alive and draining.** `docker exec prismarr php bin/console messenger:failed:show`
   is empty, and the container log carries no `Bazarr subtitle index refresh overdue` line.

## Live-verify checklist (browser, `:beta`)

- [ ] Films cold load shows muted `…` pending chips, and badges are correct after one navigation.
- [ ] A subtitle download updates that movie's badge immediately, without a page-wide stall.
- [ ] Bazarr tab: header paints instantly; switching Wanted → Movies → Series swaps only the view.
- [ ] The URL bar follows each tab switch; browser back/forward walk the views; `/bazarr/movies`
      pasted into a fresh tab still renders the full page.
- [ ] The Movies grid's search/filter/sort and lazy scroll still work after two tab switches
      (proves the observer teardown moved correctly — the leak this refactor risked).
- [ ] Cold Bazarr shows the warming skeleton, retries itself once, then offers the manual button.
- [ ] Bazarr stopped → the existing error banner inside the frame, no 500 anywhere.
- [ ] Ctrl+K feels instant on every keystroke, accented titles still match, ≤12 results, and
      DevTools shows no `_n_` key in any `data-item` attribute.
