# Responsiveness optimization — architecture decision (2026-09-01)

Control group: [2026-09-01-live-baseline-latest.md](2026-09-01-live-baseline-latest.md) (immutable).
This document records the chosen architecture and the cache semantics for the F1–F5 findings.
Branch: `perf/bazarr-responsiveness`. Status: DECIDED, pending Opus architecture review.

## Problem statement (from the baseline)

Three synchronous external refills sit in the user request path:

| Refill | Where it runs today | Cost | Cadence |
|---|---|---|---|
| Bazarr `/movies?length=-1` (5,382 rows) | `BazarrSubtitleIndex::loadMovies()` inside the first Films / quick-look / search request after the 60 s TTL | ≈7 s | every 60 s of use |
| Radarr `/api/v3/movie` (3.4k rows) | `MediaLibraryCache` (45 s) **and** `DashboardController::cached('dash.movies')` (45 s) **and** `MediaController::buildMovieSearchIndex()` (60 s) — three uncoordinated caches | ≈3.5 s | up to 3× per 45–60 s |
| Bazarr wanted/movies lists for the Bazarr tab | every `/bazarr` and `/bazarr/movies` request, uncached | 4–12 s | every page view |

Plus a CPU hotspot: `globalSearch()` transliterates every title on every request (1.6–5 s warm).

## Decisions

### D1. One shared stale-while-revalidate primitive: `App\Service\Cache\StaleWhileRevalidateCache`

Wraps `cache.app` (filesystem, atomic tmp+rename writes, shared by all FrankenPHP workers and the
Messenger consumer). Stores `{fetchedAt: int, value: mixed}` under the caller's key with
`expiresAfter(hardTtl)`.

```php
/** @return array{value: mixed, state: 'fresh'|'stale'}|null  null = hard miss (absent or past hard TTL) */
public function read(string $key, int $softTtl): ?array;
/** Blocking path (library only): pool read, and on a HARD miss compute inline through
 *  Symfony's contracts cache so LockRegistry's cross-process flock single-flight applies.
 *  (amended in review: see defect I1 — the raw-pool-only draft silently dropped the stampede
 *  protection `MediaLibraryCache` has today, and left probabilistic early expiration on.) */
public function getOrCompute(string $key, int $softTtl, int $hardTtl, callable $fetch): array;
public function write(string $key, mixed $value, int $hardTtl, ?int $fetchedAt = null): void;   // only called with a CLEAN fetch result
public function delete(string $key): void;                              // hard delete → next read is a miss
/** Best-effort single-flight: sets a `<key>.refreshing` marker (TTL 30 s) and dispatches RefreshCacheKey($key)
 *  to the `async` Messenger transport; no-op if the marker is already set. Never throws: on dispatch failure it
 *  DELETES the marker it just set and logs at error level (amended in review: see defect C4 — leaving the marker
 *  behind after a failed dispatch blackholes every refresh for the marker's full 30 s window, and pairs with a
 *  dead consumer into a permanent blackout). */
public function requestRefresh(string $key): void;
/** True when a refresh marker for $key is older than $seconds — i.e. a refresh was asked for and the
 *  consumer never delivered. Used for the dead-consumer log line and the Bazarr Retry affordance
 *  (amended in review: defect I4). */
public function refreshIsOverdue(string $key, int $seconds): bool;
```

Refresh execution is **out of the request path** in the existing `messenger-worker` s6 service
(`docker/frankenphp/s6/messenger-worker/run` → `messenger:consume async`, transport
`doctrine://default`, currently `routing: {}` — dormant infrastructure, zero new processes).
`App\Message\RefreshCacheKey` (string `$key`) is routed to `async`; `App\MessageHandler\RefreshCacheKeyHandler`
finds the owning refresher through a tagged-iterator registry of `App\Service\Cache\CacheRefresherInterface`
(`supports(string $key): bool`, `refresh(string $key): void`) and calls it. Handlers are idempotent: a refresher
first re-reads the entry and returns early if it is already fresh (a duplicate message costs one cache read).
The marker is left to expire (never deleted early) so a burst cannot re-fire within 30 s.

Why Messenger rather than `kernel.terminate`: terminate runs after the response is flushed but occupies
that worker process for the whole fetch (7 s ≈ one of four workers, once a minute). The consumer already
runs, is idle, restarts under s6, and serialises fetches system-wide for free. Why not `symfony/lock`:
single consumer + marker is enough; a duplicate refresh is idempotent and rare. Ruling recorded.

Guardrails (binding, from the safety review): every cross-request value has an explicit hard TTL; a failed
or empty fetch never overwrites a good value; refreshers check `ServiceHealthCache::isDown()` before
fetching; every per-request memo lives on a `ResetInterface` service and is cleared in `reset()`; nothing
larger than a few KB is retained in-process across requests; mutation endpoints invalidate synchronously.

*(amended in review — verified, not assumed:* `messenger:consume` is launched without `--no-reset`, and
`ConsumeMessagesCommand:221` registers `ResetServicesListener`, so `services_resetter` runs after **every**
message: per-request memos on `BazarrSubtitleIndex` / `BazarrClient` / `ConfigService` cannot leak between
messages. Worst-case dispatch→execute latency is the worker's `--sleep` default of **1 s** plus the fetch.
`docker/frankenphp/php.ini` sets `memory_limit = 1024M`, so a 5,382-row decode (~120–180 MB peak) cannot
fatal the consumer — but `--memory-limit=256M` would recycle the consumer after nearly every Bazarr refresh,
so it is **raised to 512M** (defect I5).*)*

*(amended in review — dead-consumer contract, defect I4:* if the `messenger-worker` service is down, the
library still self-heals because its hard miss blocks inline, but Bazarr data would stay hard-missing
indefinitely with no signal. Required: (a) a single `error`-level log line — `"Bazarr subtitle index refresh
overdue (requested %ds ago) — is the messenger-worker service running?"` — emitted at most once per marker
window when `refreshIsOverdue($key, 180)`; (b) an admin-only `POST /bazarr/api/refresh` that performs the
refresh **inline** (bounded by BazarrClient's own 3 s connect / 8 s total), wired to the warming panel's
manual Retry button. No unrelated request ever gains an inline Bazarr fetch.*)*

### D2. Cache semantics per dataset

| Dataset (key) | Owner / refresher | Soft | Hard | Hard-miss behaviour | Invalidated by |
|---|---|---|---|---|---|
| Radarr library `media.movies.<slug>` | `MediaLibraryCache` | 45 s | 10 min | **blocks inline** (page cannot render without it), coalesced by the marker | 17 `invalidateRadarrLibrary()` sites: hard delete |
| Sonarr library `media.series.<slug>` | `MediaLibraryCache` | 45 s | 10 min | blocks inline | `invalidateSonarrLibrary()`: hard delete |
| Bazarr movie status tuples `bazarr_subtitle_index.movies` + langs `…movie_langs` + grid cards `…movie_cards` (one fetch, three keys, written together) | `BazarrSubtitleIndex` | 60 s | 10 min | badges render `hidden`, grid fragment renders "warming" + auto-retry; refresh requested | mutations: per-id patch (see D3) + refresh request, **no hard delete** |
| Bazarr series tuples `…series` + cards `…series_cards` | `BazarrSubtitleIndex` | 60 s | 10 min | as above | as above |
| Bazarr most-missing candidates `…most_missing` (top 32 movies + top 32 series by missing count, derived from the two lists above at refresh time — no `/movies/wanted`, `/episodes/wanted` calls) | `BazarrSubtitleIndex` | 60 s | 10 min | landing strip renders "warming" | with the lists |
| Bazarr badge counts `bazarr_subtitle_index.badges` (`/api/badges`: movies/episodes wanted, providers) *(amended in review: key renamed off `bazarr.badges` so `invalidate()`'s prefix stays one namespace — defect M1)* | `BazarrSubtitleIndex` | 60 s | 10 min | tiles render "—" + warming | mutations: refresh request |
| Bazarr ping `/system/status` | `BazarrClient` | per request (1–2 ms; breaker probe) | — | existing error state | — |
| Search index `prismarr_search_{movies,series}_v3` (pre-normalized) | `MediaController` builder, sourced from `MediaLibraryCache` rows (no second Radarr fetch) | 60 s plain TTL | — | rebuild inline (≈100 ms transliteration, no network) | follows the library |
| Dashboard widget caches `dash.*` | `DashboardController` — the private `dash.movies`/`dash.series` list caches are **removed** in favour of `MediaLibraryCache` | — | — | — | now reached by library invalidation |

*(amended in review, defect M6: the decision's call-site list is incomplete — `DashboardController::movies()`/`series()` are reached from **six** places, not five: `stats()` :564-565, `recentAdditions()` :890-891, `findLibraryRow()` :945, `quickLookLibraryMatch()` :1156 (missing from the draft), `pickHeroSpotlight()` :1307, and `quickLookLibrary()` :983 via `findLibraryRow()`. The per-request `$moviesCache`/`$seriesCache` memo and its `reset()` STAY — it saves 5 redundant multi-MB `unserialize()`s per request. The `_instanceSlug`/`_instanceName` row tagging and the cross-instance fan-out move into the new per-slug loop; `cached()` itself stays for the other `dash.*` keys.)*

Bounded staleness statement: subtitle badges and Bazarr overview data may be up to 60 s old in normal
operation and up to 10 min old only while Bazarr is unreachable; library lists may be 45 s old normally
and 10 min old only while Radarr/Sonarr are unreachable. Nothing is ever served past its hard TTL.

*(amended in review — three binding corrections to this table:)*

- **Timeout parity (defect I2).** The library entry is now genuinely shared, so whoever refills it picks the
  budget for everyone. Today `BazarrPosterResolver` refills it with `RadarrClient::getMovies()`'s **8 s**
  default and `SonarrClient::getSeries()`'s **4 s** default, and `DashboardController` does the same. Every
  fetch closure handed to `MediaLibraryCache` — and `MediaLibraryRefresher` — MUST pass
  `RadarrClient::LIBRARY_TIMEOUT` / `SonarrClient::LIBRARY_TIMEOUT` (30 s). `LibraryTimeoutGuardTest` gains
  `DashboardController.php` and `BazarrPosterResolver.php` rows. (This is the exact regression class the
  2026-08-28 review's finding 1 recorded.)
- **Empty-fetch rule survives SWR (defect M7).** `MediaLibraryCache`'s current `expiresAfter($result === [] ? 0 : TTL)`
  must be preserved: a hard-miss compute that yields `[]` is returned to the caller but **never stored**, so a
  transient total failure is not pinned for the 10 min hard window.
- **Badge hard-miss renders `pending`, not nothing (defect I3).** A missing badge is visually identical to
  "nothing missing", so a hard miss would silently *lie* on a 588-card Films grid right after a deploy (the
  container's `var/cache` is not persisted). `SubtitleStatus['state']` gains a fourth value `'pending'`,
  returned by `movieStatus()`/`seriesStatus()` only when the shared map is hard-missing **and** the
  multi-instance gate passed. `media/_subtitle_badge.html.twig` renders it as a muted, non-interactive chip;
  `attachSubtitleStatus()` treats it exactly like `'hidden'` (omits the `subtitle` key) so the search JSON is
  unchanged.

### D3. Single-item surfaces (quick-look, modal chips, mutation invalidation)

`BazarrClient::getMovies(array $radarrIds = [])` / `getSeries(array $sonarrSeriesIds = [])` gain the
Bazarr `radarrid[]` / `seriesid[]` filter (verified in Bazarr source; the query string is built by hand
because `http_build_query` does not emit repeated `key[]`).

- Movie quick-look badge and `GET /bazarr/api/subtitles/movie/{id}`: read the shared map first (fresh or
  stale); on a hard miss make ONE per-id call instead of showing `hidden`. Steady state = zero extra Bazarr
  load; cold = one 1-row call, sub-second.

  **(amended in review — defect C1, CRITICAL.** The per-id fallback must NOT live in `movieStatus()` /
  `movieLanguages()`. `templates/dashboard/_quicklook_body.html.twig:21` renders its badge through
  `subtitle_status('movie', id)` — the *same* `SubtitleBadgeExtension` function `media/films.html.twig`
  calls at lines 523/768/817, i.e. 588 times per Films page. Putting the fallback behind `movieStatus()`
  turns one hard miss into **588 per-id Bazarr calls**, violating constraint #2 outright. Required shape:
  - `BazarrSubtitleIndex::movieStatus(int)` / `movieLanguages(int)` stay strictly map-only and
    never fetch (they return `pending` / `UNTRACKED_LANGS` on a hard miss and call `requestRefresh()`).
  - New `movieStatusSingle(int): SubtitleStatus` and `movieLanguagesSingle(int): MovieLangs` do the
    map-first-then-one-per-id-call dance. They are consumed by exactly two call sites:
    `BazarrController::apiSubtitlesMovie()` and a new Twig function `subtitle_status_single(kind, id)`
    used **only** by `dashboard/_quicklook_body.html.twig`.
  - A `TemplateStructureGuardTest` case asserts `subtitle_status_single(` appears in
    `dashboard/_quicklook_body.html.twig` exactly once and **zero times** in `media/films.html.twig`,
    `media/series.html.twig`, `media/_subtitle_badge.html.twig` and `templates/bazarr/`.**)**
- `apiSubtitlesSeries` keeps its existing per-id `getEpisodes()` path.
- Mutation endpoints (download/auto-search ×4): `invalidate()` becomes `refreshItem(kind, id)` — per-id
  refetch, recompute the tuple/langs/card, patch the per-request memo AND the pooled maps in place (keeping
  `fetchedAt`), then `requestRefresh()` the bulk keys and the badge counts. The acted-on item is correct
  immediately; everyone else's view catches up within one consumer cycle.

  **(amended in review — defect C2, CRITICAL: the ordering rule was undefined and the draft loses updates.**
  A bulk refresh that started *before* the mutation finishes *after* it and overwrites the patch with
  pre-mutation data — the badge the user just fixed flips back for up to 60 s, breaking constraint #4.
  Binding rule: **the bulk refresh result is authoritative except for patches recorded at or after the
  refresher's fetch start, which are re-applied on top of the fetched maps before they are written.**
  Mechanism — a small patch journal in `cache.app`:
  - Key `bazarr_subtitle_index.patches`, `expiresAfter(120)`, shape
    `array<string, array{at: int, kind: 'movie'|'series', id: int, status: SubtitleStatus, langs: MovieLangs|null, card: array|null}>`
    keyed `"<kind>:<id>"` (so repeated mutations on one item collapse to the newest).
  - `refreshItem()` writes the journal entry **and** patches the pooled maps in place (keeping each map's
    `fetchedAt`), then `requestRefresh()`s the bulk keys.
  - `BazarrIndexRefresher::refresh()` captures `$fetchStartedAt = time()` **before** calling the client;
    after a clean fetch it re-applies every journal entry with `at >= $fetchStartedAt` onto the freshly
    built maps, then writes. Entries older than the fetch start are ignored (the fetch already saw them).
  - Two concurrent `refreshItem()` calls on *different* ids can still lose one patch to a read-modify-write
    race on the pooled map; that is bounded by the 60 s soft TTL and explicitly accepted.**)**
- Global search: filter on pre-normalized fields → sort → slice to 12 → attach status for the 12 only.
  Never blocks on Bazarr (hard miss = no badge on that result).

### D4. Bazarr tab consolidation (Turbo Frame shell)

Routes `/bazarr`, `/bazarr/movies`, `/bazarr/series` keep their names and URLs. Each renders the full
page (shell = header, pill nav `[Wanted] [Movies] [Series] [History]`, `<turbo-frame id="bazarr-view">`)
on a normal request and **only the frame content** when the `Turbo-Frame` request header is present.

**(amended in review — defect C3, CRITICAL.** "Only the frame content" is wrong as written: Turbo locates
the replacement by *matching the `<turbo-frame id="bazarr-view">` element inside the response*. A response
that contains only the inner markup makes Turbo log `Response has no matching <turbo-frame id="bazarr-view">`
and leave the frame empty. `templates/bazarr/_bare.html.twig` MUST therefore emit
`<turbo-frame id="bazarr-view"> …view… </turbo-frame>` and nothing else — no `<html>`, no `{% extends %}`.
CI cannot catch this: `ServiceRouteGuardSubscriber`'s `app_bazarr_` rule 302s to the wizard before the
controller body runs unless `bazarr_url` + `bazarr_api_key` settings rows exist, so the branching test MUST
seed both settings (and pre-set the `service_down.bazarr` breaker key in `cache.app` so `ping()` returns
instantly instead of paying a 3 s connect timeout).**)**

**(amended in review — defect I6.** Both branches share one URL, so every branching action must send
`Vary: Turbo-Frame` on its response, otherwise any intermediary — or the browser's own HTTP cache — can
serve the bare fragment to a full Drive visit and vice versa.**)**
Pill links target the frame with `data-turbo-action="advance"` (URL updates, back/forward works, deep
links work, sidebar `starts with 'app_bazarr_'` active state unchanged). History stays a separate
Turbo Drive page; `/bazarr/series/{id}` drill-down unchanged.

Verified from the vendored Turbo build: `FrameRenderer.activateScriptElements()` re-creates each
`<script>` in the incoming frame with `nonce` from `<meta name="csp-nonce">`, so `_grid.html.twig`'s
inline IIFE keeps working inside a frame; `turbo:before-frame-render` / `turbo:frame-load` exist. The
grid's teardown moves from `turbo:before-render` to the frame-scoped event so observers/timers do not
leak across view switches.

*(amended in review — re-verified independently in `assets/vendor/@hotwired/turbo/turbo.index.js`:* the
minified `activateScriptElement` (`function b(e)`) sets `t.nonce` from `getMetaContent("csp-nonce")` **and
then** copies every attribute off the original element, so the template's own `nonce="{{ csp_nonce() }}"`
survives too; `base.html.twig:8` does carry `<meta name="csp-nonce">`; and the session-stable nonce means
both routes yield the currently-valid value. `data-turbo-action` is honoured for frame-scoped links
(`getVisitAction` walks link ancestors). `style-src` keeps `'unsafe-inline'`, so `_grid.html.twig`'s
`<style>` block needs no change.*)*

**(amended in review — defect I7, teardown must be dual-bound.** Frame navigations fire **no**
document-level `turbo:before-render`, so today's `document.addEventListener('turbo:before-render', teardown)`
never runs on a view switch: the previous grid's `IntersectionObserver` and search-debounce timer leak, and
one dead `document` listener accumulates per swap. Required: bind teardown to `turbo:before-frame-render`
on the `#bazarr-view` element **and** keep the document-level `turbo:before-render` binding for the
direct-hit / full-page-nav case; `teardown()` removes both and is idempotent. Guard with a
`TemplateStructureGuardTest` case asserting `_grid.html.twig` contains both event names and that
`removeEventListener` appears for each.**)**

View data comes only from the D2 caches. Warm → tens of ms. Cold (hard miss) → the fragment renders the
existing card chrome with a skeleton + "Loading subtitle data from Bazarr…" and the frame reloads itself
once after ~4 s (`<turbo-frame>.reload()`), then shows a manual Retry. Bazarr down → existing error
banner inside the frame. Hidden views are not fetched; the two other views may be prefetched only after
`turbo:frame-load` of the visible one and only if the beta measurement shows benefit (default: off).
Default view stays **Wanted** because it is now cache-served.

Landing content: stat tiles from `bazarr.badges`; "Most missing" from `…most_missing` (series ranked by
aggregate `episodeMissingCount` — a deliberate semantic change from per-episode rows, ruled acceptable).
Movies/Series grids: cards from `…movie_cards` / `…series_cards` joined with posters from
`BazarrPosterResolver` (library cache) at render time — posters are not stored in the Bazarr cache so
their freshness stays tied to the library.

### D5. Global search (F3)

`buildMovieSearchIndex()` / `buildSeriesSearchIndex()` store `_n_title`, `_n_original`, `_n_sort`
computed once with a single reusable `Transliterator` instance and source rows from `MediaLibraryCache`
(normalized rows contain every field the index needs). `globalSearch()` filters with `str_contains` on the
pre-normalized fields, sorts with the pre-normalized title (same "starts-with first, then `strcasecmp`
on raw title" order), slices to 12, attaches subtitle status to the 12, and strips `_n_*` before
`json()` (the client stringifies the whole item into a `data-item` attribute). Cache keys bump to `_v3`.

**(amended in review — defect I8: "normalized rows contain every field the index needs" is not literally
true.** Two verified gaps, both benign once mapped explicitly, both silent data loss if left to the
implementer to guess:
- `SonarrClient::normalizeSeries()` (`SonarrClient.php:1544+`) emits **no `originalTitle` key at all**.
  Today's `buildSeriesSearchIndex()` reads `$s['originalTitle'] ?? null` off the raw row, which is also
  `null` because Sonarr's `/api/v3/series` has no such field — so the series index must keep emitting
  `'originalTitle' => null` and `_n_original` becomes `''`. No behaviour change; just make it explicit.
- `poster`: `MediaController::extractPoster()` falls back `remoteUrl ?? url`, while
  `RadarrClient::imageUrl()` / `SonarrClient::imageUrl()` return `remoteUrl ?? null`. Switching the index
  to normalized rows therefore drops the local-`url` fallback. Accepted — the Films/Series grids and the
  dashboard already render posters from the normalized rows, so search now *matches* the rest of the app,
  and a null poster degrades to the renderer's existing placeholder.
- `sortTitle`: normalized rows are already `strtolower(...)` with a `title` fallback; today's index reads
  the raw `sortTitle ?? ''`. Pre-normalizing through the transliterator makes the two identical.
- `hasFile` for series stays hardcoded `true` (unchanged).
- `id`, `title`, `year` and the `instance` tag are unaffected.**)**

### D6. Films/Series navigation and HTML size (F5) — deferred to a second stage

Investigate after F1–F4 land and are measured. Not in this branch unless a P0/P1 change requires it.

## Expected effect vs baseline

| Route | Baseline cold / warm | Target |
|---|---|---|
| Films | 11.5 s / 318 ms | ≤3.5 s once per 10 min worst case (library hard miss), otherwise ≈320 ms; Bazarr never in the path |
| Movie quick-look | 11.5 s / 232 ms | <1 s cold (per-id), ≈230 ms warm |
| Global search `?q=mat` | 9.8 s / 1.6 s | tens of ms warm; cold bounded by the index rebuild (no network) |
| Bazarr landing | 10.2 s / 7.0 s | shell <50 ms; view tens of ms warm; "warming" state cold |
| Bazarr Movies | 4.2 s / 7.5 s | same |
| Dashboard `widget/recent` | 3.8 s cold | shares the library entry; cold hit paid once per hard window across dashboard/films/search |
| Bazarr calls per minute (browsing) | ≈8 | ≤1 `/movies`, ≤1 `/series`, ≤1 `/badges` per 60 s + pings |

## Test strategy

Unit tests with `ArrayAdapter` + fake clients + Messenger `InMemoryTransport`/fake bus: SWR read
fresh/stale/miss; write-only-on-clean-fetch; marker single-flight; refresher idempotence; per-id patch;
invalidation hard-delete for library, patch+refresh for Bazarr; search ordering/accents/cap/no `_n_*`
in JSON; frame-vs-full render branching on the `Turbo-Frame` header; `{#`/`#}` balance guard for new
inline JS. Full suite + PHPStan level 7 + `lint:twig` + `lint:container` before the `:beta` build.

---

## Opus review

Adversarial architecture review of D1–D6, 2026-09-01. Method: read CONTEXT.md, the six investigation
reports (A–F), baseline §1–4, then the actual code — `BazarrSubtitleIndex`, `BazarrClient`,
`BazarrPosterResolver`, `MediaLibraryCache`, `ServiceHealthCache`, `BazarrController`,
`MediaController` (films / globalSearch / index builders / the 17 invalidate sites), `DashboardController`,
`RadarrClient::normalizeMovie`, `SonarrClient::normalizeSeries`, the Bazarr/media/dashboard templates,
`messenger.yaml`, `cache.yaml`, the s6 run scripts, the test bootstrap — and the vendored
`symfony/runtime`, `symfony/messenger`, `symfony/cache`, `symfony/framework-bundle` and
`@hotwired/turbo` builds.

**Verdict: the architecture is sound and should ship — but not as drafted.** Four Critical and nine
Important defects were found; all are amended in place above and carried into the implementation plan
(`docs/superpowers/plans/2026-09-01-perf-bazarr-responsiveness.md`). Nothing in D1–D5 violates F's
guardrails once amended, and nothing violates the CONTEXT constraints or upstream mergeability.

### Critical

**C1 — Per-id fallback on the shared badge path becomes an N+1 (588 Bazarr calls per page).**
D3 puts the "hard miss ⇒ one per-id call" fallback behind the movie quick-look badge. That badge renders
through `subtitle_status('movie', id)` (`dashboard/_quicklook_body.html.twig:21`) — the *same*
`SubtitleBadgeExtension` function `media/films.html.twig` calls at :523/:768/:817, once per card. One hard
miss on Films would fan out to 588 per-id Bazarr calls, directly violating CONTEXT constraint #2 ("never
N+1 over a library grid").
*Resolution:* `movieStatus()`/`movieLanguages()` stay map-only and never fetch. New
`movieStatusSingle()`/`movieLanguagesSingle()` carry the fallback, consumed **only** by
`apiSubtitlesMovie()` and a new `subtitle_status_single()` Twig function used only in
`dashboard/_quicklook_body.html.twig`. A source-guard test asserts zero uses in the films/series/
`_subtitle_badge`/`templates/bazarr` files.

**C2 — Lost update: the per-id patch versus an in-flight bulk refresh.**
No ordering rule was defined. A bulk refresh that started *before* a subtitle download completes *after*
it and overwrites the patch with pre-mutation data — the badge the user just fixed flips back for up to
60 s. Breaks constraint #4.
*Resolution:* ordering rule made explicit — **the bulk refresh is authoritative except for patches
recorded at or after its fetch start, which are re-applied on top before the write.** Implemented with a
120 s `bazarr_subtitle_index.patches` journal keyed `"<kind>:<id>"`; the refresher captures
`$fetchStartedAt` before the client call and re-applies newer journal entries onto the fetched maps. The
residual read-modify-write race between two concurrent `refreshItem()` calls on *different* ids is bounded
by the 60 s soft TTL and explicitly accepted.

**C3 — A frame response that omits the `<turbo-frame>` element renders an empty view, and CI cannot catch it.**
D4 says the branch returns "only the frame content"; Turbo locates the replacement by *finding
`<turbo-frame id="bazarr-view">` inside the response*. Without it Turbo logs "Response has no matching
`<turbo-frame id="bazarr-view">`" and blanks the view. `ServiceRouteGuardSubscriber`'s `app_bazarr_` rule
302s to the wizard before the controller body runs, so `BazarrControllerTest` today only exercises the
redirect — this would ship broken, the same class of bug as the 2026-08-30 `#}`-vs-`*/` regression
(`ced9170`).
*Resolution:* `bazarr/_bare.html.twig` emits `<turbo-frame id="bazarr-view">…</turbo-frame>` and nothing
else. The branching test seeds `bazarr_url` + `bazarr_api_key` settings rows (to clear the route guard)
and pre-sets the `service_down.bazarr` breaker key in `cache.app` so `ping()` short-circuits instead of
paying a 3 s connect timeout.

**C4 — A failed dispatch blackholes refreshes.**
`requestRefresh()` sets the marker and then dispatches; on a dispatch failure (SQLite `BUSY`, DB down) the
marker survives its full 30 s with no refresh queued. Combined with a dead consumer this is a permanent
blackout with no signal.
*Resolution:* on dispatch failure, delete the marker just set and log at `error`. On the success path the
marker is still left to expire, preserving burst suppression.

### Important

**I1 — Replacing `CacheInterface::get()` with a raw pool silently drops cross-process stampede
protection, and leaves probabilistic early expiration on.** Report D asserted Symfony's contracts cache
only coalesces in-process without `symfony/lock`. That is wrong: `ContractsTrait::setCallbackWrapper()`
installs `LockRegistry::compute(...)` by default on every non-CLI SAPI, and `LockRegistry` takes an
**flock** on one of 20 vendor files keyed by the cache key — genuine cross-worker single-flight, which
`MediaLibraryCache` has today. Separately, `get()`'s default `$beta = 1.0` enables early recompute
proportional to the last computation time (3.5 s): harmless at a 45 s TTL, but at a 600 s hard TTL it
fires random inline refills tens of seconds early.
*Resolution:* SWR's blocking path (`getOrCompute`) goes through `CacheInterface::get(..., beta: 0)`; the
non-blocking read and the marker use the raw pool. Both interfaces resolve to the same `cache.app`
`FilesystemAdapter`.

**I2 — Library-timeout parity.** Once the entry is genuinely shared, whoever refills it picks the budget
for everyone. `BazarrPosterResolver:63/79` refills with `getMovies()`'s **8 s** default and
`getSeries()`'s **4 s** default; `DashboardController::movies()/series()` do the same. This is exactly the
2026-08-28 review's finding-1 regression class.
*Resolution:* every fetch closure handed to `MediaLibraryCache`, plus `MediaLibraryRefresher`, passes
`RadarrClient::LIBRARY_TIMEOUT` / `SonarrClient::LIBRARY_TIMEOUT`; `LibraryTimeoutGuardTest` gains
`DashboardController.php` and `BazarrPosterResolver.php` rows.

**I3 — Hard-miss "hidden badges" lie.** An absent badge is visually identical to "nothing missing".
`var/cache` is not persisted across deploys, so the first Films load after every deploy would show a full
588-card grid of silently-wrong "all good" cards.
*Resolution:* a fourth state `'pending'`, rendered as a muted non-interactive chip;
`attachSubtitleStatus()` treats it exactly like `'hidden'` so the search JSON contract is unchanged.

**I4 — Dead-consumer hole.** If `messenger-worker` is down, the library still self-heals (its hard miss
blocks inline) but Bazarr data stays hard-missing indefinitely and unobservably.
*Resolution:* `refreshIsOverdue($key, 180)` drives one `error` log line per marker window, plus an
admin-only `POST /bazarr/api/refresh` doing an inline, breaker-gated, 8 s-bounded refresh, wired to the
warming panel's manual Retry button. No unrelated request ever regains an inline Bazarr fetch.

**I5 — Consumer memory budget.** `docker/frankenphp/php.ini` sets `memory_limit = 1024M`, so a 5,382-row
decode (~120–180 MB peak: ~10 MB body, ~215k array entries) cannot fatal the consumer — good. But
`--memory-limit=256M` is a *recycle* threshold checked between messages, so the consumer would restart
after nearly every Bazarr refresh (~1–2 s kernel boot each time).
*Resolution:* raise `docker/frankenphp/s6/messenger-worker/run` to `--memory-limit=512M`.

**I6 — No `Vary: Turbo-Frame`** on the two-shape routes, so an intermediary or the browser HTTP cache can
serve the bare fragment to a full Drive visit and vice versa. *Resolution:* set it on every branching
action.

**I7 — `_grid.html.twig`'s teardown never runs under frames.** Frame navigations fire no document-level
`turbo:before-render`, so the previous grid's `IntersectionObserver` and search-debounce timer leak and one
dead `document` listener accumulates per swap — the leak class already recorded for the torrent pollers.
*Resolution:* dual-bind `turbo:before-frame-render` on `#bazarr-view` **and** the existing document-level
`turbo:before-render`; `teardown()` removes both and is idempotent; a template guard asserts both event
names and both `removeEventListener` calls.

**I8 — D5's "normalized rows contain every field the index needs" is false.**
`SonarrClient::normalizeSeries()` emits no `originalTitle` key at all; `imageUrl()` drops
`MediaController::extractPoster()`'s `remoteUrl ?? url` fallback; `sortTitle` is already lowercased.
*Resolution:* explicit field mapping recorded in D5. Net behaviour is unchanged for `originalTitle`
(Sonarr's API has no such field either) and the poster now matches what the rest of the app renders.

**I9 — Constructor churn breaks existing tests.** `MediaLibraryCache` is constructed directly in
`MediaLibraryCacheTest` (6×) and `BazarrPosterResolverTest` (2×); `DashboardController` is constructed
positionally in `DashboardControllerTest` (10×), and that class's own convention is "nullable + last so
legacy positional test constructors keep working".
*Resolution:* `MediaLibraryCache(StaleWhileRevalidateCache $swr)` with all 8 constructions updated in the
same commit; any new `DashboardController` dependency goes last and nullable.

### Minor

- **M1** `bazarr.badges` renamed `bazarr_subtitle_index.badges` — one namespace for `invalidate()`.
- **M2** Five keys are now written from one fetch and cannot be written atomically. The refresher captures
  `$now = time()` once and stamps all five; write order is cards → most_missing → badges → langs → status,
  so the badge keys (shortest visible lifetime) flip last. Cross-key skew is cosmetic and sub-second.
  Rejected the alternative of one fused entry: it would add ~500 KB of card data to every Films request's
  `unserialize()`, a measurable regression against the 318 ms warm baseline.
- **M3** `RefreshCacheKeyHandler` resolves the key through the tagged registry and, on no match, logs a
  warning and **returns** (acks) rather than throwing — a throw costs 3 retries then a `failed`-queue entry
  nobody monitors. The key is app-constructed, never user input, and cache-pool keys are hashed by Symfony,
  so there is no path-traversal surface; the handler must still never use it as a path.
- **M4** `messenger_messages` is not in the test schema — `AbstractWebTestCase::resetSchema()` uses
  `SchemaTool` over ORM metadata only, and `Version20260419220010` deliberately omits the table. The
  Doctrine transport's `auto_setup` would create it on first send, but that couples every WebTestCase that
  renders a Bazarr/Films page to a live SQLite write. Cleaner: `config/packages/test/messenger.yaml`
  routing `async` to `in-memory://` (Task 0) — which also makes "exactly one message dispatched"
  assertions trivial.
- **M5** `MediaLibraryCache::TTL` keeps its value and name (it becomes the *soft* TTL), so
  `UsenetController::RECENT_HISTORY_TTL`'s "matches MediaLibraryCache::TTL" docblock stays true.
- **M6** The decision's dashboard call-site list missed `quickLookLibraryMatch()` (`:1156`) — six sites,
  not five.
- **M7** `MediaLibraryCache`'s "empty result is not cached" rule must survive the SWR swap
  (`testEmptyResultIsNotCached` is an existing guard).

### Refuted / verified-safe (recorded so nobody re-litigates)

- **Service reset in the consumer is real.** `messenger:consume` runs without `--no-reset` and
  `ConsumeMessagesCommand:221` registers `ResetServicesListener`, which resets on every non-idle
  `WorkerRunningEvent`. Per-request memos on `BazarrSubtitleIndex` / `BazarrClient` / `ConfigService`
  cannot leak across messages.
- **SQLite contention is not a new risk.** The consumer already polls `messenger_messages` on this
  connection every second today (`routing: {}` means it polls an empty queue). Only the dispatch `INSERT`
  is new: ≤1 per 30 s per key thanks to the marker. Pickup latency is the worker's `--sleep` default of
  1 s plus the fetch — well inside the "warming" UX budget.
- **`kernel.terminate` was correctly rejected.** `FrankenPhpWorkerRunner:57-61` calls `terminate()` after
  `frankenphp_handle_request()` returns — post-flush, but before that same worker accepts its next
  request, so a 7 s refresh costs one of N workers for 7 s. Messenger is the right call.
- **Turbo frame script activation works under this CSP.** In the vendored 7.3 build,
  `activateScriptElement` sets `nonce` from `getMetaContent("csp-nonce")` **and then** copies every
  attribute off the original element; `base.html.twig:8` carries the meta; the nonce is session-stable so
  both paths yield a valid value. `data-turbo-action` is honoured for frame-scoped links (`getVisitAction`
  walks link ancestors). `style-src` keeps `'unsafe-inline'`, so `_grid`'s `<style>` block needs no change.
- **`_search_modal.html.twig` needs no change.** Included once from `base.html.twig:1717` (admin-gated),
  binding a capture-phase listener on `document` — outside the frame, bound once, unaffected by swaps. The
  bare fragment correctly does not re-include it.
- **`symfony/lock` is still not needed.** Single consumer + marker + the (restored) `LockRegistry` flock on
  the inline path is enough; a duplicate refresh is idempotent and rare.
- **The multi-instance gate short-circuits before any map access** (`BazarrSubtitleIndex:96-98`), so a
  two-Radarr install never even requests a refresh from the badge path. The Bazarr *tab* correctly does not
  apply the gate (Bazarr's own ids, no collision risk), so the refresher must not skip on it.
- **Security posture unchanged.** New routes sit under `app_bazarr_api_` (guard-exempt via the existing
  `exclude_prefix`) and inherit the class-level `#[IsGranted('ROLE_ADMIN')]`; fail-closed JSON shapes are
  preserved; nothing new enters caches or logs beyond ids and counts.
- **Upstream mergeability is fine.** `BazarrSubtitleIndex`, `BazarrClient`, `BazarrPosterResolver`,
  `BazarrController` and `templates/bazarr/` are fork-only. Shared-file edits (`MediaController`,
  `DashboardController`, `MediaLibraryCache`, `messenger.yaml`, `_subtitle_badge.html.twig`,
  `_quicklook_body.html.twig`, the s6 run script) are additive and surgical; no upstream file, service or
  route is renamed or moved; `src/Service/Cache/`, `src/Message/`, `src/MessageHandler/` are new,
  upstream-neutral namespaces.

### D6

Correctly deferred. Films' 2.45 MB HTML / 588 badges is the next ceiling once F1–F4 land, but it is a
separate change with its own measurement, and nothing in D1–D5 depends on it.

---

## As built (2026-09-02)

Recorded against `git log --oneline main..HEAD` on `perf/bazarr-responsiveness` (16 commits, Tasks
0–10). D1–D5 shipped as designed; every deviation below was a lead ruling made during implementation
or review, not a silent drift — each is cross-referenced to the SDD ledger (`progress.md`) for the
implementer/reviewer exchange that produced it.

- **`most_missing` shipped as two keys, not one.** `BazarrSubtitleIndex::KEY_MOST_MISSING_MOVIES`
  (`bazarr_subtitle_index.most_missing_movies`) and `KEY_MOST_MISSING_SERIES` (`…most_missing_series`)
  are written independently by `BazarrIndexRefresher::refreshMovies()` / `refreshSeries()`, each owning
  only the dataset its own fetch produced. `BazarrController::index()` reads both, merges and re-ranks
  them for the landing strip. D2's prose spoke of one `…most_missing` key; splitting it keeps each
  refresher single-writer over its own keys, which the two-refresher split (movies fetch vs. series
  fetch) required.
- **`bazarr.badges` shipped as `bazarr_subtitle_index.badges`** (`BazarrSubtitleIndex::KEY_BADGES`) —
  recorded in D2 as amendment M1 during the Opus review, not a post-review drift, but confirmed here
  against the actual constant name for anyone grepping the code from this doc.
- **`SubtitleStatus` gained a fourth state, `'pending'`**, exactly as D2/I3 specified: rendered as a
  muted, non-interactive chip (`translations/*.yaml` key `bazarr.badge.pending`: *"Subtitle status is still
  loading from Bazarr"*), and treated identically to `'hidden'` by `attachSubtitleStatus()` so the
  search JSON contract is unchanged.
- **The per-id fallback shipped exactly where D3/C1 required and nowhere else**:
  `BazarrSubtitleIndex::movieStatusSingle()` / `movieLanguagesSingle()` (map-first, one per-id
  `BazarrClient` call only on a hard miss) are consumed by exactly two call sites —
  `BazarrController::apiSubtitlesMovie()` / `apiSubtitlesLanguages()` and the Twig function
  `subtitle_status_single()`, itself used only by `dashboard/_quicklook_body.html.twig`.
  `movieStatus()` / `movieLanguages()` stay strictly map-only, as designed; a `TemplateStructureGuardTest`
  case (Task 8) pins the zero-uses-elsewhere invariant.
- **The patch journal shipped as designed**: `BazarrSubtitleIndex::KEY_PATCHES`
  (`bazarr_subtitle_index.patches`, `expiresAfter(120)`), keyed `"<kind>:<id>"`, written by
  `refreshItem()` alongside an in-place patch of the pooled maps. `BazarrIndexRefresher::refresh()`
  captures `$fetchStartedAt` before the client call and re-applies every journal entry with
  `at >= $fetchStartedAt` onto the freshly fetched maps before writing (C2's ordering rule) — write
  order is cards → most_missing → badges → langs → status per M2, after a fix round corrected an
  implementer's first pass that wrote badges last (`progress.md`, Task 6 review → `fa3bbff`).
- **`POST /bazarr/api/refresh`** (route name `app_bazarr_api_refresh`, `#[IsGranted('ROLE_ADMIN')]`
  inherited from the controller) shipped as the dead-consumer recovery path, wired to the warming
  panel's manual Retry button. Two rulings sharpened it past the original D1/I4 sketch during review
  (`fab9e22`):
  - it **never calls `invalidate()`/hard-deletes first** — an early draft wiped the index and then
    reported `ok: true` regardless of what the refetch actually produced, which is a lie the moment the
    refetch itself fails or the breaker is open;
  - it now reports **truthfully**: `ok` is `true` only when both `movies` and `series` keys were
    re-read fresh after the attempt, with `reason` set to `breaker_open` or `fetch_failed` otherwise
    (HTTP always 200, fail-closed shape, never a 500).
- **No blanket `invalidate()` on mutations.** The plan's original D3 text ("`invalidate()` becomes
  `refreshItem(kind, id)`") was overridden during the Task 7 review: a subtitle download / auto-search
  never hard-deletes the shared Bazarr keys — it patches the acted-on item in place (pooled maps +
  journal) and `requestRefresh()`s the bulk keys plus badges. A blanket delete would reintroduce the
  hard-miss `pending`-chip regression on every *other* item on the page for one refresh cycle, for no
  benefit over the bounded, already-accepted staleness the patch mechanism gives the acted-on item.
  Cost if this ruling is ever revisited: one stale row for at most one soft-TTL window, which is what
  shipped anyway.
- **Badge counts are read through the movies refresher, not fetched separately.** `KEY_BADGES` has no
  Bazarr call of its own — `BazarrIndexRefresher::supports()` and `refresh()` route it onto the same
  `getMovies()` fetch that fills `KEY_MOVIES`, so a badge-only refresh request costs zero extra Bazarr
  load; it piggybacks on whichever bulk fetch is already in flight or next scheduled.
- **A clean empty fetch writes empty maps, not a permanent `pending`.** An early draft (Task 5) added
  an `$rows === []` guard that skipped the write entirely on an empty Bazarr response — which is
  correct for "unreachable/errored" but wrong for "this install genuinely has zero wanted items": it
  pinned every badge at `pending` forever and re-dispatched a refresh on every read. Fixed in `21afa96`
  — only `getLastError() !== null` (or the breaker being open) skips the write; an actually-empty,
  successful response is written as an empty map like any other clean result.
- **The Bazarr tab's per-view templates were folded into the shell**, not kept alongside it.
  `templates/bazarr/movies.html.twig` and `series.html.twig` were deleted in Task 10 in favour of
  `_shell.html.twig` (the persistent chrome) plus `_bare.html.twig` (the `<turbo-frame>`-only response)
  and the existing `_grid.html.twig` view partial — one source of the Wanted/Movies/Series markup
  instead of three near-duplicate full-page templates, which is what let the frame-vs-full branching
  in D4/C3 stay a single conditional in `BazarrController::index()/movies()/series()` rather than three.
- **`BazarrSubtitleIndex::invalidate()` has no production caller left** after the no-blanket-invalidate
  ruling above; it is kept (unused, tested) as the mechanism an admin-settings save would reasonably
  want later, per the Task 7 review note — a deliberate keep, not dead-code drift.
- **Consumer memory limit:** `docker/frankenphp/s6/messenger-worker/run` raises
  `--memory-limit` from an explicit **256M to 512M** (D1/I5), documented inline in the run script
  itself: `php.ini`'s `memory_limit = 1024M` is the real ceiling that prevents a fatal, but a
  256M *recycle* threshold would restart the consumer after nearly every Bazarr refresh.
- **Everything else in D1–D5 shipped as written**: the `beta: 0` `getOrCompute()` call, the
  `requestRefresh()` delete-marker-on-dispatch-failure behaviour (C4), `Vary: Turbo-Frame` on the
  branching routes (I6), the dual-bound `turbo:before-frame-render` / `turbo:before-render` teardown
  in `_grid.html.twig` (I7), the explicit `originalTitle`/`poster`/`sortTitle` field mapping for the
  `_v3` search index (I8), and the nullable-last `MediaLibraryCache`/`DashboardController` constructor
  changes (I9) all landed exactly as the amended decisions describe, verified by the tests each task
  added (`StaleWhileRevalidateCacheTest`, `RefreshCacheKeyHandlerTest`, `BazarrFrameRenderTest`,
  `TemplateStructureGuardTest`, `GlobalSearchNormalizationTest`).

Deferred minors (accepted, not fixed — see `progress.md` for the full list): a hard-missing langs key
blanks the status map and self-heals within one soft TTL; the transitional PHPStan ignore for an
unused `BazarrClient` constructor param was removed once Tasks 7/8 started using it; two concurrent
`refreshItem()` calls on *different* ids can still lose one patch to a read-modify-write race on the
pooled map, bounded by the 60 s soft TTL and explicitly accepted (C2); an episode download inside the
soft window updates that episode's own badge immediately but the aggregate badge *count* only catches
up at the next soft-TTL expiry.
