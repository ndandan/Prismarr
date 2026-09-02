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

## `:beta` timing table — FILLED 2026-09-02 (live Unraid, image 31d6f99)

| Route | Baseline cold / warm | `:beta` cold / warm | Δ (warm) | Target | Pass? |
|---|---|---|---|---|---|
| Films `/medias/radarr-1/films?status=all&sort=title-asc` | 11,515 / 318 ms | 320 / 318 ms (p95 325 over 15) | cold −97 %, warm 0 % | ≤3.5 s worst case once per 10 min; ≈320 ms otherwise; Bazarr never in the path | ✅ (no cold spike in 220 s probe; 592 badges, 0 pending) |
| Movie quick-look `quicklook/movie/radarr-1/8172` | 11,488 / 232 ms | 218 / 237 ms (probe max 224 over 20 ticks) | cold −98 %, warm +2 % | <1 s cold, ≈230 ms warm | ✅ |
| Global search `?q=mat` | 9,780 / 1,613 ms | 377 / 26 ms (p95 41) | −98 % | tens of ms warm | ✅ |
| Global search `?q=the` (series slug) | — / 4,980 ms | 32 / 31 ms | −99 % | tens of ms warm | ✅ |
| Global search `?q=zzzzqq` (0 results) | — / 1,613 ms | 15 / 13 ms | −99 % | tens of ms warm | ✅ |
| Bazarr landing `/bazarr` | 10,170 / 7,019 ms | 218 / 221 ms (full load DCL 348 ms) | −97 % | shell <50 ms, view tens of ms warm | ✅ (view served from cache; shell+view in one response) |
| Bazarr Movies `/bazarr/movies` | 4,171 / 7,494 ms | 234 / 240 ms | −97 % | same | ✅ |
| Bazarr Series `/bazarr/series` | 664 / 5 ms | 14 / 17 ms | +12 ms | no regression | ✅ (noise-level) |
| Bazarr History `/bazarr/history` | 5 / 5 ms (162 KB — see note) | 9,179 / 9,124 ms (3.9 MB) | not comparable | out of scope (unchanged code) | ⚠️ pre-existing inline fetch; fast-follow |
| Dashboard `widget/recent` | 3,766 / 240 ms | 246 / 261 ms | cold −93 %, warm +9 % | shares the library entry; cold paid once per hard window | ✅ (3.7 s seen only on the very first load after the container restart) |
| Discover `/decouverte` (control, Bazarr-free) | 4,146 / 3,516 ms | 4,084 / 3,686 ms | +5 % (TMDb noise) | unchanged — control | ✅ |
| Series `/medias/sonarr-1/series` | 1,172 / 66 ms | 91 / 85 ms (p95 82 over 15) | cold −92 %, warm +19 ms | no regression | ✅ (noise-level) |
| Health `/api/health/services` | 3 / 2 ms | 3 / 2 ms | 0 | — | ✅ |
| Live widgets poll `widgets?w=…` | 335 / 339 ms (p95 901) | 607 / 335 ms (p95 491) | 0 | — | ✅ |
| Tautulli / UniFi / Prowlarr / Calendar shells | 11 / 5 / 163 / 186 ms | 14 / 4 / 155 / 189 ms | 0 | — | ✅ |

Note on History: the baseline sample returned 162 KB in 5 ms, identical in size to the Movies page, which is not a plausible render of the history lists; the `:beta` number (3.9 MB of history rows, 9 s) is the real cost of the unchanged inline `/movies/history` + `/episodes/history` fetches. Nothing on this branch touches that path (verified by `git diff 8a3e4c5..HEAD`). Fast-follow: page or cache History through the SWR primitive.

Measured 2026-09-02 ~13:00–13:20 local on the same Unraid host, same libraries, same method (in-page fetch TTFB, N=5; N=15 for the tail table; 220 s probe). Full write-up: [2026-09-02-beta-live-comparison.md](2026-09-02-beta-live-comparison.md).

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
