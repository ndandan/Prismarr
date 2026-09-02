# Live `:latest` vs `:beta` comparison — 2026-09-02

Control: [2026-09-01-live-baseline-latest.md](2026-09-01-live-baseline-latest.md) (`main-8a3e4c5`, measured 2026-09-01 evening).
Treatment: `ghcr.io/ndandan/prismarr:beta` built from `31d6f99` (branch `perf/bazarr-responsiveness`), force-updated
onto the same Unraid container (`http://192.168.68.83:7070`) by the operator on 2026-09-02; version stamp confirmed
on the Updates page (`31d6f99`, fork main `8a3e4c5`). Same host, same libraries (~3.4k movies, ~300 series; Bazarr
now reports 5,337 wanted movies / 13,745 wanted episodes / 3 providers), same measurement method and routes as the
baseline (§10 repeat protocol), run 30–40 minutes after the deploy.

## 1. Server-side latency (TTFB ms, N=5; first sample = cold where applicable)

| Route | `:latest` cold / warm | `:beta` cold / warm | Change (warm) |
|---|---:|---:|---:|
| Dashboard shell | 4 / 3 | 17* / 3 | 0 |
| `widget/recent` | 3,766 / 240 | 246 / 261 | cold −93 % |
| `widget/hero` | 280 / 218 | 279 / 228 | 0 |
| Live widgets poll | 335 / 339 | 607 / 335 | 0 |
| Health services | 3 / 2 | 3 / 2 | 0 |
| **Films** | **11,515 / 318** | **320 / 318** | **cold −97 %, warm 0** |
| Series | 1,172 / 66 | 91 / 85 | cold −92 % |
| **Movie quick-look** | **11,488 / 232** | **218 / 237** | **cold −98 %** |
| Series quick-look | 295 / 10 | 30 / 9 | 0 |
| Movie detail (Bazarr-free) | 8 / 5 | 7 / 5 | 0 |
| **Search `?q=mat`** | **9,780 / 1,613** | **377 / 26** | **−98 %** |
| **Search `?q=the`** | — / 4,980 | 32 / 31 | **−99 %** |
| **Search `?q=zzzzqq`** | — / 1,613 | 15 / 13 | **−99 %** |
| Online search (TMDb) | 436 / 231 | 2,046 / 227 | 0 (TMDb variance) |
| **Bazarr landing** | **10,170 / 7,019** | **218 / 221** | **−97 %** |
| **Bazarr Movies** | **4,171 / 7,494** | **234 / 240** | **−97 %** |
| Bazarr Series | 664 / 5 | 14 / 17 | +12 ms |
| Bazarr History | 5 / 5 (see note) | 9,179 / 9,124 | not comparable |
| Modal chips `api/subtitles/movie/8172` | 2 / 2 | 9 / 9 | +7 ms |
| Discover (control) | 4,146 / 3,516 | 4,084 / 3,686 | +5 % (TMDb) |
| Tautulli / UniFi / Prowlarr / Calendar | 11 / 5 / 163 / 186 | 14 / 4 / 155 / 189 | 0 |

\* first navigation right after the container restart; every later shell TTFB was 3 ms.

## 2. Tail latency (15 sequential samples, TTFB ms)

| Route | `:latest` p50 / p95 / max | `:beta` p50 / p95 / max |
|---|---:|---:|
| Films | 318 / 9,252 / 9,252 | 313 / 325 / 325 |
| Series | 66 / 1,172 / 1,172 | 72 / 82 / 82 |
| Movie quick-look | 232 / 3,496 / 3,496 | 238 / 257 / 257 |
| Search `?q=mat` | 1,613 / 5,030 / 5,030 | 25 / 41 / 41 |
| Live widgets poll | 339 / 901 / 901 | 351 / 491 / 491 |
| Bazarr landing | 7,019 / 7,307 / — | 232 / 244 / 244 |
| Bazarr Movies | 7,494 / 8,226 / — | 236 / 245 / 245 |
| Online search | 231 / 436 / 436 | 222 / 2,376 / 2,376 (TMDb) |

## 3. Periodic-spike probe (movie quick-look every ~11 s for 220 s)

`:latest`: spikes of 9,172 / 3,488 / 7,043 / 3,495 / 9,050 / 3,491 ms at the 45 s and 60 s cache expiries.
`:beta`: 20 ticks, min 195 ms, **max 224 ms, zero spikes**. Series quick-look max 26 ms (was 1,212). Health poll
max 285 ms (was 1,214). The recurring stall is gone; refreshes now happen in the messenger consumer.

## 4. Frontend full-page loads

| Page | `:latest` TTFB / DCL / load / requests | `:beta` TTFB / DCL / load / requests |
|---|---|---|
| Dashboard | 4 / 219 / 450 ms / 49 | 17 / 279 / 286 ms / 48 |
| Films (warm) | 338 / 731 / 770 ms / 158 | 313 / 838 / 973 ms / 140 (588 badges, 0 pending, 0 Bazarr calls) |
| Series | 822 / 1,127 / 1,148 ms / 91 | 75 / 376 / 414 ms / 100 |
| Bazarr landing | 12,174 / 12,234 / 12,238 ms / 37 | 241 / 348 / 375 ms / 32 |

Films load/DCL variance (+100–200 ms) is client-side rendering of the same 2.45 MB HTML; TTFB is unchanged.

## 5. Turbo navigation (`Turbo.visit` → `turbo:load`, foreground tab)

| Target | `:latest` fetch / total | `:beta` fetch / total |
|---|---|---|
| Dashboard | 7–14 / 76–837 ms | 7–8 / 530–1,017 ms (client render, same range of noise) |
| Tautulli | 17–528 / 549–744 ms | 22 / 583 ms |
| UniFi | 7–11 / 31–716 ms | 36 / 726 ms |
| Discover | 3,839 / 4,757 ms | 3,556 / 3,740 ms |
| Bazarr landing | 9,925 / 10,814 ms | 218 / 715 ms |

Bazarr frame switches (new): Wanted→Movies 1,527 ms, Movies→Series 1,258 ms, Series→Wanted 1,472 ms measured to
`turbo:frame-load` (server 220–240 ms; the rest is parsing the 1.3 MB Movies fragment and lazy-rendering 60 cards).
URL advanced on every switch, the frame element persisted, back/forward history grew by one entry per switch.
"All movies" landing button: frame-scoped `advance`, URL → `/bazarr/movies`, 60 cards. Series card click →
full-page `/bazarr/series/362` (title rendered, no "Content missing").

## 6. Bazarr / Radarr / Sonarr call behaviour (inferred from request behaviour; no host shell access)

- Films, quick-look, search: 0 Bazarr calls per request (baseline: one full `/movies?length=-1` per 60 s inline).
- Bazarr landing/Movies/Series: 1 ping per view (baseline: ping + 2–4 full-list calls per view).
- The subtitle index was filled by the consumer within the first minute after deploy: the first Films fetch already
  showed 592 badges and 0 pending chips, and the landing showed real counts, not the warming panel — this is the live
  proof that the web workers and the messenger consumer share one cache pool.
- Library refill still happens: the first dashboard load after the restart paid 3.7 s once (hard miss blocks inline
  by design); no later sample in 40 minutes exceeded 271 ms on `widget/recent`.
- Not measured (needs the Unraid shell): exact per-minute counts in the Bazarr/Radarr logs, `messenger:failed:show`,
  `docker stats` for the web workers and the `messenger-worker` process. See §8.

## 7. Qualitative

- Films opens in ~0.3 s every time; no more 9–11 s hang on the first visit after a minute idle.
- Ctrl+K feels instant; result lists appear on every keystroke.
- Bazarr tab: header and pills paint immediately, view content follows within ~0.25 s server time; switching tabs
  repaints only the card area.
- Dashboard: unchanged feel; Recent additions arrive in ~0.25 s.
- Discover: unchanged (~3.7 s, TMDb-bound), which validates the run.
- Bazarr History: ~9 s, unchanged code path, now visible as the slowest page in the app (see fast-follows).

## 8. Release questions

1. **Is `:beta` faster than `:latest`?** Yes, on every route the branch targeted; every other route is within noise.
2. **Did the Bazarr-related slowdown improve?** Yes. The recurring 5.5–8 s Bazarr penalty on Films, quick-look and
   search is gone (probe max 224 ms vs 9,172 ms); Bazarr landing/Movies dropped from 7 s to 0.22 s.
3. **Any routes where `:beta` regressed?** No route the branch touched regressed. Bazarr History measures 9 s, but its
   code is unchanged and the baseline's 5 ms sample is not credible (see the note in the benchmarks file); it is a
   pre-existing cost now exposed as the slowest page — fast-follow, not a blocker.
4. **Is repeated navigation more responsive?** Yes: p95 Films 325 ms (was 9,252), quick-look 257 (was 3,496),
   search 41 (was 5,030), Bazarr 244 (was 7,307). Turbo Drive navigation to the dashboard/Tautulli/UniFi is unchanged.
5. **Are tail-latency spikes reduced?** Yes: the 220 s probe shows zero spikes on any of the four endpoints.
6. **Is worker memory/stability at least as good?** Not verifiable from the browser. Operator check required:
   `docker stats prismarr` over ≥1 h (web workers, baseline 135–160 MiB/worker) and the `messenger-worker` process
   (should plateau, not recycle on every refresh); `docker exec prismarr php bin/console messenger:failed:show` empty;
   no "refresh overdue — is the messenger-worker service running?" line in the container log.
7. **Did any optimization increase external load?** No in steady state: full-list Bazarr fetches went from one per
   60 s of use (plus one per Bazarr-tab view) to at most one `/movies` + one `/series` + one `/badges` per 60 s
   regardless of traffic; Radarr full-list fetches went from up to three per 45–60 s to one. Admin Retry adds
   ≤3 bounded calls per click, coalesced.

**Recommendation:** promote `perf/bazarr-responsiveness` to `main` once the operator confirms the §8.6 memory /
consumer checks. Fast-follows (not blockers): cache or page Bazarr History; the deferred minors listed in the
architecture doc's as-built section.
