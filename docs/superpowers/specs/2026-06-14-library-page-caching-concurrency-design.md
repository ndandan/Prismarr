# Library Page Caching + Concurrent Fetch — Design

**Date:** 2026-06-14
**Status:** Approved (design); pending implementation plan
**Scope:** Optimizations #1 (cache library list) and #2 (parallelize per-page upstream calls). Items #3–#5 from the performance review (lower/standardized timeouts + latency-aware circuit breaker, persistent connection reuse, page-slice-only normalization) are explicitly **out of scope** here and revisited afterward.

## Problem

The Radarr/Sonarr library pages (`MediaController::films()` / `series()`) are the source of the 10–15 s page-load complaints. Two root causes, confirmed by tracing the hot paths:

1. **No data caching on the library pages.** Every visit re-fetches and re-normalizes the entire library. `getMovies()` on a large library is a big payload normalized into a deep nested array + 4 `DateTimeImmutable` objects per movie (`RadarrClient::normalizeMovie()`, `RadarrClient.php:1468`). The dashboard already caches (`DashboardController::cached()`, 45 s TTL), but the library pages never got the same treatment.
2. **Sequential, blocking upstream calls.** `RadarrClient::get()` (`RadarrClient.php:1558`) and the mirror in `SonarrClient` use raw blocking `curl_exec`, one request at a time. `films()` makes **5** sequential calls (`system/status`, `movie`, `queue`, `indexer`, `health`); `series()` makes **4** (`system/status`, `series`, `queue`, `calendar`). With `CONNECTTIMEOUT=4` + `TIMEOUT=8` per call, a single slow/unreachable backend stacks to 10–40 s. The `ServiceHealthCache` circuit breaker (`ServiceHealthCache.php`, 10 s TTL) only mitigates *fully-dead* services *after* the first failure — a slow-but-alive backend (HTTP 200 after 6 s) never trips it and pays the full timeout on every call, every page.

The language/runtime (PHP 8 / Symfony on FrankenPHP worker mode) is **not** the problem. The dominant cost is waiting on sequential network I/O to external services, which is language-independent.

## Key insight driving the design

The heavy call (`getMovies` / `getSeries`) is large but **slow-changing** → ideal to **cache**. The other calls (`status`, `queue`, `indexer`, `health`, `calendar`) are cheap but **volatile** (the queue changes constantly) → ideal to **parallelize, not cache**. So:

- **Warm page** = one cache hit (the list) + one concurrent batch of cheap calls.
- **Cold page** = one concurrent batch that includes the list fetch.

Either way, page latency collapses from the *sum* of the calls to the *single slowest* call, and timeout-stacking when a service is down collapses from N×8 s to one 8 s wait.

## Components

Follows the existing convention: **caching lives in the controller/service layer; the HTTP client stays a thin wrapper.**

### `MediaLibraryCache` (new service)

A small, well-bounded service owning the short-TTL cache for the heavy library list.

- **Interface:**
  - `movies(string $slug, callable $fetch): array`
  - `series(string $slug, callable $fetch): array`
  - `invalidate(string $type, string $slug): void` — `$type` is `'radarr'` or `'sonarr'`.
- **Cache keys:** `media.movies.<slug>` / `media.series.<slug>`, keyed per instance slug so one instance's data never masks another's.
- **TTL:** 45 s (matches `DashboardController::WIDGET_CACHE_TTL`).
- **Empty-result rule:** an empty list is **not** cached (expires immediately), mirroring the dashboard's `expiresAfter($result === [] ? 0 : TTL)` self-heal so a transient total failure isn't pinned for the whole window.
- **Backing store:** the existing `cache.app` filesystem pool (via `CacheInterface`), same as the dashboard.

### `multiGet()` (new method on `RadarrClient` and `SonarrClient`)

Concurrent raw fetch via `curl_multi`, encapsulating all concurrency inside the client.

- **Signature (conceptual):** `multiGet(array $requests): array` where `$requests` is a map `name => ['path' => string, 'params' => array]`, returning `name => (decoded array | null)`.
- **Per-handle options:** reuse the *exact* current `get()` options — SSRF protocol guard (`CURLPROTO_HTTP | CURLPROTO_HTTPS` on both `PROTOCOLS` and `REDIR_PROTOCOLS`), `CONNECTTIMEOUT=4`, `TIMEOUT=8`, `NOSIGNAL=1`, `X-Api-Key` + `Accept: application/json` headers.
- **Circuit breaker:** if `ServiceHealthCache::isDown()` for the bound instance, short-circuit the whole batch to nulls instantly (no handles created). On a per-handle network error / HTTP 0, set `serviceUnavailable` and `ServiceHealthCache::markDown()`. On a successful 200, `clear()` the down marker. Same semantics as the current `get()`.
- **Scope:** only the ~5 read endpoints on the hot paths use this. The ~95 write/admin methods are untouched.
- **Normalization:** `multiGet` returns raw decoded payloads. Existing normalizers are **reused, not duplicated** — the relevant normalizer(s) (e.g. movie/series row normalization) are exposed so the controller can normalize batch results and feed `MediaLibraryCache` without copying logic.

### `MediaController::films()` / `series()` (rewired)

1. Check `MediaLibraryCache` for the list.
2. Issue **one** `multiGet` batch for the cheap endpoints, **plus** the list endpoint only on a cache miss.
3. Normalize via the reused normalizers; on a cold load, store the normalized list into `MediaLibraryCache`.
4. Filter / sort / paginate and render exactly as today (no template or query-param changes).

## Data flow

**Films page (warm cache):**
1. `MediaLibraryCache->movies(slug)` → hit.
2. `multiGet(['status'=>…, 'queue'=>…, 'indexer'=>…, 'health'=>…])` — 4 concurrent calls.
3. Normalize queue/indexers/health; combine with cached movie list.
4. Filter/sort/paginate → render.

**Films page (cold cache):**
1. `MediaLibraryCache->movies(slug)` → miss.
2. `multiGet(['status'=>…, 'movie'=>…, 'queue'=>…, 'indexer'=>…, 'health'=>…])` — 5 concurrent calls.
3. Normalize; store normalized movie list into `MediaLibraryCache` (unless empty).
4. Filter/sort/paginate → render.

**Series page** is the same shape with `status`, `series`, `queue`, `calendar`.

## Write-through invalidation

The library mutations live in the **same** `MediaController` as the reads (`films_add`, `films_delete`, `films_monitor`, films bulk refresh/search, films file delete, films rename, and the Sonarr/series equivalents). After a **successful** write, the action calls `MediaLibraryCache->invalidate('radarr'|'sonarr', $slug)`.

Policy is deliberately blunt-but-correct: any successful mutation drops that instance's list cache, and the next page load refetches once. This guarantees adds/deletes/monitor-toggles are reflected immediately rather than lingering for up to the TTL.

## Error handling — behavior preserved

No user-visible behavior change when a service is actually down:
- `ServiceHealthCache` down → batch short-circuits to nulls instantly (as today).
- Per-handle network error / HTTP 0 → marks the instance down, trips the breaker (as today).
- A null `system/status` still sets `$error = true` and renders the existing error state.

The only difference is the elimination of N×8 s timeout-stacking: a fully-down service costs one timeout window, not one per call.

## Testing

- **`MediaLibraryCache`** (unit): key-per-slug isolation; empty result not cached; TTL behavior; `invalidate()` drops the right key.
- **`multiGet`** (unit): returns a correctly-keyed map; partial per-handle failure marks the instance down; open circuit breaker short-circuits the whole batch without creating handles.
- **Controller** (smoke, in the style of the existing Tautulli smoke tests): films/series render on both warm and cold cache; a mutating action invalidates the list cache.

## Out of scope (revisit after this lands)

- #3 — lower/standardize timeouts + make the circuit breaker trip on latency, not just hard errors.
- #4 — persistent connection reuse (keep-alive / connection pool) under worker mode.
- #5 — normalize only the visible page slice instead of the whole library.

A full migration of the clients to Symfony HttpClient (which would deliver #2 + #4 idiomatically) was considered and **deferred** as too large / high-risk for this change; the surgical `curl_multi` approach targets the exact hot paths with minimal blast radius.
