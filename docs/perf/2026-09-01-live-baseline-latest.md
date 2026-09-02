# Live `:latest` performance baseline — 2026-09-01

**Control group for the Bazarr-era optimization work.** Measured against the production Unraid
instance at `http://192.168.68.83:7070`, image `ghcr.io/ndandan/prismarr:latest` stamped
`main-8a3e4c5` (the Bazarr merge). Nothing on the live instance was modified; all probes were
read-only GETs from a logged-in browser tab (ECC chrome-devtools MCP, real Chrome, LAN).

Library size on this instance: ~3.4k movies (Radarr, 200 cards/page), ~300 series (Sonarr),
Bazarr reporting **6,219 wanted movies / 13,758 wanted episodes / 2 providers**. 12/12 services up.

## Method

* Server-side latency = `PerformanceResourceTiming.responseStart − requestStart` (TTFB) of an
  in-page `fetch()` with a unique cache-buster and `cache:'no-store'`, 5 sequential samples per
  route (15 for the tail-latency table). **Cold** = first sample after the relevant server cache
  expired; **warm** = subsequent samples. Median/p95/max reported, never averages.
* Frontend = Navigation Timing of a real full-page load (TTFB, DOMContentLoaded, `load`,
  request count, slowest request) and `turbo:visit → turbo:load` for Turbo navigations.
* A 220 s background probe hit the same four endpoints every ~11 s to expose the periodic
  cache-expiry spikes (the "random request takes several seconds" the operator reported).
* Reproduction harness: see *Repeat protocol* at the end. Raw numbers live in the session
  scratch notes; this file keeps the release-acceptance figures.

## 1. Server-side latency per route (TTFB, ms)

| Route | Cold (first) | Warm median | Warm p95 | Notes |
|---|---:|---:|---:|---|
| Dashboard shell `/tableau-de-bord` | 4 | 3 | 5 | Shell only; data arrives via fragments below |
| ↳ `widget/recent` | **3,766** | 240 | 249 | Cold = Radarr+Sonarr library refill (45 s TTL). No Bazarr. |
| ↳ `widget/hero` | 280 | 218 | 227 | TMDb + upcoming |
| ↳ `widget/upcoming` | 20 | 6 | 8 | |
| ↳ `widget/recommendations` | 7 | 6 | 7 | |
| ↳ `widget/requests` | 14 | 10 | 12 | |
| ↳ `widgets?w=server,health,plex,network` (30 s poll) | 335 | 339 | 901 | Unraid GraphQL + UniFi dominate; spikes to 0.6–0.9 s |
| `/api/health/services` (topbar poll) | 3 | 2 | 2 | 10 s shared cache; one sweep/10 s costs 0.9–1.2 s (seen in probe) |
| Films `/medias/radarr-1/films?status=all&sort=title-asc` | **11,515** | 318 | 329* | 2.45 MB HTML, 588 subtitle badges. Cold = library refill **+ Bazarr `/movies` refill** |
| Series `/medias/sonarr-1/series` | 1,172 | 66 | 73* | 2.1 MB HTML, 384 badges. Cold = library + Bazarr `/series` |
| Discover `/decouverte` | 4,146 | 3,516 | 3,687 | TMDb-bound on every request — the **control route** (Bazarr-free) |
| Movie quick-look `quicklook/movie/radarr-1/8172` | **11,488** | 232 | 239* | Renders ONE badge → pays the full Bazarr movie refill when cold |
| Series quick-look `quicklook/series/sonarr-1/362` | 295 | 10 | 30 | |
| Movie detail `/medias/radarr-1/films/8172/detail` | 8 | 5 | 6 | Bazarr-free single-item fetch |
| Global search `/medias/radarr-1/search?q=mat` (Ctrl+K) | **9,780** | **1,613** | 1,630* | See finding F3 — slow even warm, 0 results still 1.6 s |
| Global search `?q=the` (12 hits, series slug) | — | **4,980** | 4,992 | Match-heavy terms 3× slower |
| Online search `/search/online?q=matrix` | 436 | 231 | 253 | TMDb |
| Bazarr landing `/bazarr` | **10,170** | **7,019** | 7,307 | **No cache**: ping + badges + 6.2k wanted movies + 13.8k wanted episodes + 2 poster maps every load |
| Bazarr Movies `/bazarr/movies` | 4,171 | **7,494** | 8,226 | Full `/movies?length=-1` (5,382 rows, 1.29 MB HTML) every load |
| Bazarr Series `/bazarr/series` | 664 | 5 | 5 | `/series` list is small → cheap |
| Bazarr History `/bazarr/history` | 5 | 5 | 5 | |
| `bazarr/api/subtitles/movie/8172` (modal chips) | 2 | 2 | 3 | Served from the 60 s index |
| Tautulli `/tautulli` | 12 | 11 | 12 | Shell; data via pollers |
| UniFi `/unifi` | 5 | 5 | 5 | Shell; data via pollers |
| Prowlarr `/prowlarr` | 260 | 163 | 176 | |
| Calendar `/calendrier` | 235 | 186 | 212 | |

\* p95 excluding the cold sample; the 15-sample tail table below includes it.

## 2. Tail latency, 15 sequential samples (TTFB ms, includes whatever cache expiry fell inside the window)

| Route | min | p50 | p95 | max |
|---|---:|---:|---:|---:|
| Dashboard shell | 3 | 3 | 5 | 5 |
| Films page | 308 | 318 | **9,252** | 9,252 |
| Series page | 57 | 66 | 1,172 | 1,172 |
| Live widgets (combined poll) | 308 | 339 | 901 | 901 |
| Health services | 2 | 2 | 2 | 2 |
| Movie quick-look | 209 | 232 | 3,496 | 3,496 |
| Global search `?q=mat` | 1,586 | 1,613 | 5,030 | 5,030 |
| Online search | 203 | 231 | 436 | 436 |

## 3. Periodic-spike probe (movie quick-look, one badge; every ~11 s for 220 s)

| t (s) | quick-look movie | quick-look series | movie detail (no Bazarr) | interpretation |
|---:|---:|---:|---:|---|
| 9 | **9,172** | 1,154 | 31 | both caches cold |
| 21–42 | 218–230 | 10–11 | 7–11 | steady state |
| 56 | **3,488** | 239 | 30 | library refill only (45 s TTL) |
| 84 | **7,043** | 44 | 7 | **Bazarr movie-index refill only (60 s TTL) — isolated Bazarr cost** |
| 109 | 3,495 | 330 | 17 | library only |
| 171 | **9,050** | 1,212 | 6 | both |
| 217 | 3,491 | 273 | 7 | library only |

Steady state: quick-look movie ≈ 215 ms, series ≈ 11 ms, detail ≈ 9 ms. Health poll 5–1,214 ms
(the once-per-10 s sweep).

## 4. Bazarr cost isolation (the A/B the live instance allows)

Bazarr could not be disabled on the live instance, so the delta is isolated by cache-expiry
timing (section 3) and by pairing Bazarr-free routes with their Bazarr-bearing twins:

| What | Bazarr-free | With Bazarr | Bazarr delta |
|---|---:|---:|---:|
| Cold refill, movie side | 3.5 s (library only: `widget/recent`, probe t=56/109/217) | 9.0–11.5 s (films page / movie quick-look) | **+5.5 to +8 s once per 60 s**, paid by the first Films load, movie quick-look or Ctrl+K search after expiry |
| Cold refill, series side | 0.24–0.33 s | 1.15–1.2 s | +0.9 s once per 60 s |
| Warm request | films 315 ms / quick-look ~215 ms | same | ≈ 0 (badges come from the 60 s tuple cache) |
| Bazarr `/movies?length=-1` alone | — | 4–8 s (from `/bazarr/movies`, 5,382 rows) | this single call is the whole movie-side cost |
| Bazarr tab landing | — | 7–12 s per load, every load | uncached wanted lists (6.2k + 13.8k rows) |

Bazarr call count per 60 s window at steady state (single worker pool, filesystem `cache.app`):
1 × `/movies`, 1 × `/series`, plus 1 × `/system/status` ping per health sweep. Every Bazarr-tab
page adds its own uncached full-list calls per view. `:beta` must not raise these counts.

## 5. Frontend full-page loads (real navigation)

| Route | TTFB | DOMContentLoaded | load | decoded HTML | requests | slowest request |
|---|---:|---:|---:|---:|---:|---|
| Dashboard | 4 ms | 219 ms | 450 ms | 214 KB | 49 | `widget/recent` 3,754 ms (cold), `widget/hero` 3,645 ms |
| Films (warm) | 338 ms | 731 ms | 770 ms | 2,456 KB | 158 (91 img, 52 fetch) | `library-filter-memory.js` 29 ms; Deluge poll-summary ×N |
| Films (cold) | 3,549 ms | 4,126 ms | 4,147 ms | 2,456 KB | 158 | — |
| Series | 822 ms | 1,127 ms | 1,148 ms | 2,098 KB | 91 | `/api/health/services` 232 ms |
| Bazarr landing | **12,174 ms** | 12,234 ms | 12,238 ms | 180 KB | 37 | (server time is everything) |
| Bazarr Movies | **6,953 ms** | 7,223 ms | 7,226 ms | 1,287 KB | 83 | 60 cards rendered lazily of 5,382 |
| Bazarr Series | 664 ms | 781 ms | 833 ms | 218 KB | 64 | |

## 6. Turbo navigation (`Turbo.visit` → `turbo:load`, foreground tab)

| Target | fetch | total (incl. client render) | note |
|---|---:|---:|---|
| Dashboard | 7–14 ms | 76–837 ms | render-bound on the client; two runs 76/145 ms, later runs 500–840 ms |
| Tautulli | 17–528 ms | 549–744 ms | |
| UniFi | 7–11 ms | 31–716 ms | |
| Discover | 3,839 ms | 4,757 ms | server-bound (TMDb) |
| Bazarr landing | 9,925 ms | 10,814 ms | server-bound |
| Films / Series / Prowlarr / Calendar / Deluge / Seerr | — | full reload | these templates set `turbo-visit-control=reload`; every visit is a full page load |

## 7. Qualitative pauses observed as a user

1. **Ctrl+K search feels laggy on every keystroke**: 1.6–5 s per query even warm; the first
   query after a quiet minute can take ~10 s.
2. **First Films visit / first movie quick-look after ~1 minute idle hangs 9–11 s** with the
   browser spinner (full reload, so no Turbo progress bar either). Subsequent visits are ~0.3 s.
3. **Bazarr tab is slow on every visit** (7–12 s landing, 4–8 s Movies), with a Turbo progress bar
   crawling; the rest of the app is unaffected while it loads.
4. Dashboard shell is instant, but the *Recent additions* row and hero stats arrive 3.7 s later
   when the 45 s library cache is cold; otherwise ~0.25 s.
5. Live-widget poll (Unraid/UniFi) occasionally takes 0.6–1.2 s; invisible unless watching the
   network panel.
6. Discover is consistently ~3.5–4.7 s (TMDb, unchanged by Bazarr) — the control route.

## 8. Findings ranked for the optimization phase

* **F1 — Bazarr movie-index refill is synchronous and in the request path** (`BazarrSubtitleIndex::loadMovies`
  → `BazarrClient::getMovies` `/movies?length=-1`, 4–8 s on 5.4k rows, 60 s TTL). This is the
  operator's "feels slower since Bazarr": +5.5–8 s on the first Films / quick-look / search
  request each minute. Candidates: longer TTL + stale-while-revalidate/background refresh,
  or the Bazarr `radarrid[]`-filtered endpoint for single-item surfaces.
* **F2 — Bazarr tab has no cross-request cache** (landing 7–12 s, Movies 4–8 s, every load).
* **F3 — Global search re-transliterates every title on every request** (`MediaController::globalSearch`
  `$normalize` inside the loop and again inside `usort`): 1.6 s with zero matches, 5 s with many.
  Upstream-original code (Apr 2026), not Bazarr, but Bazarr adds the cold 60 s penalty on top.
  Fix: normalize once when building the 60 s index.
* **F4 — 45 s library cache** makes the movie side pay a 3.5 s Radarr refill every 45 s of use
  (pre-existing; visible on dashboard `widget/recent`, Films, quick-look).
* F5 — Films/Series HTML is 2.1–2.5 MB per full load, and these pages bypass Turbo
  (pre-existing design; sets the floor for "repeated navigation" on the library pages).

## 9. Limitations

* Worker memory / process stability of `:latest` is not observable from the UI (Unraid widget
  shows only host RAM and container state) — compare via `docker stats prismarr` on the host.
* Bazarr's own API latency was not measured directly (needs the API key); it is inferred from
  `/bazarr/movies` (single call + render) and the probe isolation.
* The dev/test environment has no Bazarr and no real-size library, so no toggle A/B was run
  there; the live cache-expiry isolation above is the A/B.

## 10. Repeat protocol for the `:beta` comparison

Run from a logged-in tab on the same host, same time of day, with the same libraries:

```js
// paste in the console; returns TTFB stats per route (N samples, first = cold if cache expired)
async function bench(urls, N = 5) {
  performance.setResourceTimingBufferSize(20000); performance.clearResourceTimings();
  const q = a => { const s = [...a].sort((x, y) => x - y), p = k => s[Math.min(s.length - 1, Math.ceil(s.length * k) - 1)]; return { first: a[0], p50: p(.5), p95: p(.95), max: s[s.length - 1] }; };
  const out = {};
  for (const u of urls) { const t = [];
    for (let i = 0; i < N; i++) { const m = 'b' + Math.random(); const r = await fetch(u + (u.includes('?') ? '&' : '?') + '_b=' + m, { cache: 'no-store' }); await r.text();
      const e = performance.getEntriesByType('resource').find(x => x.name.includes(m)); t.push(Math.round(e.responseStart - e.requestStart)); }
    out[u] = q(t); }
  return out;
}
```

Routes to pass: every row of section 1. Then repeat the 220 s probe (section 3), the full-page
loads (section 5, `performance.getEntriesByType('navigation')[0]`), and the Turbo chain
(section 6). Count Bazarr/Radarr/Sonarr calls per minute from the Bazarr/Radarr/Sonarr logs on
the host while the probe runs. Release rule: no important warm route may regress beyond
benchmark noise (±10–15 %), and F1/F2 must improve.
