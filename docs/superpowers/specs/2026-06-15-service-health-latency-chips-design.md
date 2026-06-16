# Design: latency-aware service health chips

**Date:** 2026-06-15
**Status:** Approved (design), pending implementation plan
**Scope:** Dashboard "Services health" widget only

## Problem

The dashboard "Services health" card renders one chip per configured service /
instance, each showing only a binary **Up / Down** state (`isHealthy()` →
`?bool`). It carries no sense of *how well* a service is responding, and no
latency. We want each chip to show a richer status plus a response-time reading.

## Goal

Each chip shows:

- Service name (unchanged)
- Status word + latency, e.g. `Up · 142 ms`

with five possible statuses driven by response time and reachability:

| Status      | Trigger                                                        |
|-------------|---------------------------------------------------------------|
| `up`        | live ping succeeds, latency `0–750 ms`                        |
| `slow`      | live ping succeeds, latency `751–2000 ms`                     |
| `very_slow` | live ping succeeds, latency `> 2000 ms`                       |
| `down`      | live ping fails — timeout / connection refused / auth failure |
| `degraded`  | circuit breaker open: a stale cached-down verdict is served without a live probe this cycle ("cached stale data") |

Threshold edges: `≤ 750` → up, `≤ 2000` → slow, else very_slow.

## Non-goals

- No change to `/api/health/services`, the topbar health indicator, or any
  media client's `ping()` contract.
- No new "reachable-but-erroring = degraded" classification — per the status
  table, auth/5xx failures count as **down**. `degraded` is reserved for the
  stale-verdict (circuit-breaker) case, the only true "cached stale data"
  signal that exists today.

## Architecture

### 1. `HealthService::statusFor()` — new core probe

```php
statusFor(string $service, ?string $instanceSlug = null): array
// → ['status' => 'up'|'slow'|'very_slow'|'degraded'|'down'|null, 'latencyMs' => ?int]
```

Resolution order:

1. **Not configured** — when a `ConfigService` is wired and `isConfigured()`
   is false: `['status' => null, 'latencyMs' => null]`. The caller drops the
   chip (mirrors today's `null` from `isHealthy()`).
2. **Circuit breaker open** — `ServiceHealthCache::isDown($service, $instanceSlug)`
   true: `['status' => 'degraded', 'latencyMs' => null]`. We are serving a
   stale down-verdict from a prior failure without a fresh live probe → degraded,
   not a freshly-confirmed down.
3. **Live probe** — time `pingFor()` with `hrtime(true)`:
   - ping returns `false` → `'down'`, no latency.
   - ping returns `null` (unknown slug etc.) → `status null` (drop chip).
   - ping returns `true` → bucket by `latencyMs`: `≤750` up · `≤2000` slow ·
     else very_slow.

Results are cached in-process for `CACHE_TTL` (10 s), storing the full status
struct (incl. latency), mirroring the existing `isHealthy()` cache.

### 2. `isHealthy()` refactored to delegate

`isHealthy()` becomes a thin projection over `statusFor()`:

- `up` / `slow` / `very_slow` → `true`
- `down` / `degraded` → `false`
- `null` → `null`

This keeps the public `?bool` contract **byte-for-byte identical** for the
topbar and `/api/health/services` (a breaker-open service already returns
`false` there today), while removing duplicated config/breaker logic. One probe
path, no observable behavior change outside the dashboard.

### 3. `DashboardController::servicesHealth()`

Swap `isHealthy()` → `statusFor()`. Each chip becomes
`['id' => …, 'name' => …, 'status' => …, 'latencyMs' => …]`. Chips with
`status === null` are dropped (unchanged drop semantics).

### 4. Template — `dashboard/_health.html.twig`

- Dot class keyed by status: `is-up`, `is-slow`, `is-very-slow`,
  `is-degraded`, `is-down` (fallback `is-unk`).
- State line: translated status word, then `· {{ latencyMs }} ms` only when
  `latencyMs` is non-null (up / slow / very_slow).

### 5. CSS — five dot colors (in `dashboard/index.html.twig`)

| Class           | Color                |
|-----------------|----------------------|
| `is-up`         | green `#22c55e` (existing `is-ok`) |
| `is-slow`       | amber `#f59e0b`      |
| `is-very-slow`  | orange-red `#f97316` |
| `is-degraded`   | slate-gray `#94a3b8` |
| `is-down`       | red `#ef4444`        |

Latency gradient green → amber → orange; gray for stale/uncertain; red for dead.

### 6. Translations

Add `dashboard.health.status.{up,slow,very_slow,degraded,down}` to every locale
file. Keep the existing `dashboard.health.status_ok` / `status_ko` keys — the
topbar still uses them.

## Testing (TDD)

- **`statusFor()` unit tests:** each latency bucket (up/slow/very_slow), `down`
  on failed ping, `degraded` when the breaker is open, `null` when unconfigured.
- **`isHealthy()` regression:** still returns the same booleans (up→true,
  down/degraded→false, unconfigured→null) after the delegation refactor.
- **Widget render test:** chips show the status word and a `ms` reading.

## Open detail (resolve during implementation)

Confirm whether `RadarrClient` / `SonarrClient` mark the breaker **with** the
instance slug. If they mark without it, `isDown($service, $slug)` won't match
and those chips fall through to a live probe — showing `down` after a timeout
instead of `degraded`. Acceptable as a fallback; align the slug if cheap.
