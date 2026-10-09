# Wokeometer integration

Prismarr can show a [Wokeometer](https://wokeometer.app) score, a one-line
summary and a link to the full analysis for any title in the Wokeometer
catalog (library items, Discover, dashboard), right inside the detail views.
It is optional, off until you paste an API key, and designed around one rule:

> **Opening a movie or series never calls the Wokeometer API. API calls caused by opening a media detail view: 0.**

Wokeometer's API is prepaid and billed per request, so Prismarr does not look
titles up as you browse. Instead a background job downloads the catalog into
a local SQLite table once (when you ask for it), keeps it fresh with a small
monthly top-up, and every detail view reads that local table. Browsing is free
and works offline from Wokeometer's point of view.

```
Wokeometer API  ──(initial full sync on Sync now, then monthly incremental sync, worker only)──▶  SQLite (wokeometer_media)
                                                                                                   │ indexed local lookup by (tmdb_id, media_type)
                                                                                                   ▼
                                                                  Global quick-look · Films/Series modals · Discover modal
```

## What you need

- A Wokeometer account and a developer API key, created at
  [wokeometer.app/account/developer](https://wokeometer.app/account/developer).
  Keys look like `wok_` followed by 64 hex characters. Prismarr refuses to
  save anything that is not `wok_` followed by at least 16 letters or digits
  (you get a warning that the other settings were saved but the key was not, and the stored key is kept). The trash button next to the key field removes the stored key.
- Prepaid API credits. **1 credit = $0.05**, and every successful API request
  costs one credit. Failed requests (4xx/5xx) are not billed. Check the
  pack sizes on Wokeometer's own pricing page: the smallest pack (100 credits,
  $5) is **not** enough for the initial sync; the $50 pack is.
- A running messenger worker. It is part of the standard Prismarr container
  (s6 service `messenger-worker`), so there is nothing to add. See
  [Worker requirements](#worker-requirements) below.

Enable it under **Settings → Services → Metadata enrichment → Wokeometer**:
paste the key, leave "Enabled" and "Automatic sync" on, and save.

**Saving a key does not start a billed sync.** Press **Sync now** when you are
ready to run the initial catalog sync (about 200 billed requests, roughly
$10). The scheduler never starts that first sync by itself: it only resumes
an interrupted run you started, and runs the small monthly incremental syncs
once the initial sync has completed.

## What it costs

| Item | Requests | Approx. cost |
|---|---|---|
| One page of the catalog | 1 request = 1 credit = 50 titles | $0.05 |
| Initial full sync (movies + TV), started only by **Sync now** | **279 on a real install (Sept 2026)** | **about $14** |
| Monthly incremental sync (automatic) | about 2-60 (whatever changed) | about $0.10-$3 |
| Opening a movie or series | 0 | $0 |
| "Full resync" button | same as the initial sync (about 280) | about $14 |
| Hard ceiling per run (safety cap) | 600 | $30 |

The catalog held 13,916 rows at the first live sync (4,330 movies, 3,292 TV
series and 6,294 individual seasons), i.e. 279 pages of 50. It grows over
time, so expect the initial sync to cost a little more each month you wait. Every request, including an empty page, costs
one credit.

The precise guarantee is:

- **per run, at most 600 billed requests** (the run then stops by itself);
- **automatic scheduling pauses after a runaway stop** (the 600-request cap,
  or a paging cursor that stops moving forward) **or an unreadable response**
  (it was still billed, so it is never retried on a timer): nothing runs again
  until you press **Sync now**;
- **the initial full sync is manual-only**: the scheduler never starts it.

The card in Settings shows the billed requests of the last run, your lifetime
billed requests and the credits remaining as last reported by Wokeometer, so
you can watch the spend. Prismarr never estimates dollars itself; use the
$0.05 figure above.

## What is stored, and what is not

Stored, per title, in the local `wokeometer_media` table: Wokeometer id, media
type (movie or tv), the external source and id it reported, the TMDb id
derived from them, parent id and season number (for season rows), title,
release date, the woke score (0-10, or none), the TL;DR text, the public page
slug, the "analyzed" flag and sync timestamps. Sync bookkeeping (watermark,
cursor, lock, statistics) lives in the single-row `wokeometer_sync_state`
table.

Deliberately **not** stored: poster images, overviews or synopses (these come
from third parties and Wokeometer's terms forbid redistributing them; the
detail views already get posters and synopses from Radarr, Sonarr and TMDb),
audience scores and rating counts, and collection ids.

## Sync behaviour

- **Initial full sync (manual).** With no completed full sync on record, a
  run walks the movie catalog and then the TV catalog, 50 titles per request.
  Only **Sync now** starts it.
- **Monthly incremental sync (automatic).** After a full sync has completed,
  the next run is due 30 days after the last success and asks only for titles
  updated since the previous run started, minus a 48 hour overlap so nothing
  is missed at the boundary. Re-seeing a title is harmless: rows are upserted
  by Wokeometer id and never overwritten with older data. A `last_updated`
  date that is not a plain ISO-8601 timestamp is ignored, and one more than a
  day in the future is capped at tomorrow, so a bad value cannot freeze a row.
- **Chunked and paced.** A run works in chunks of at most 8 pages, with 1.2
  seconds between pages, then hands the rest to itself as a new job so the
  single background worker keeps serving its other jobs. A phase ends only
  when Wokeometer returns no next cursor (an empty page that still carries a
  cursor is followed).
- **Rate limits and outages.** A rate-limit response (HTTP 429) is waited out
  (using Wokeometer's `Retry-After`, capped at 15 minutes); after 10 in a row
  the run stops and resumes an hour later. A temporary server or network
  failure is retried after 30 seconds, up to 3 failures in a row, then the run
  stops and resumes an hour later. Neither is billed.
- **Idempotency keys.** Each page request carries an `Idempotency-Key` that is
  saved before the request is sent and reused if the request has to be
  retried or the container restarts mid-page. A retried page is **replayed
  free within Wokeometer's 24 h idempotency window**.
- **The watermark only advances on success.** The "last updated" mark that
  drives the incremental sync is committed only when both the movie and the
  TV phase have finished. An interrupted run resumes from its saved cursor
  instead of starting over.
- **Settings changes apply mid-run.** The settings are re-read before every
  page: switching Wokeometer off (or removing the key) stops a running sync
  within one page; it resumes from its cursor once you switch it back on.
- **Deletions.** Wokeometer's API does not report removed titles. After a
  completed **full** sync, rows that were not seen during that run are
  removed, separately for movies and for TV, and only for a type whose phase
  returned at least one title (an empty phase is treated as an API glitch and
  never wipes that part of the local copy). Incremental syncs never delete.
- **Safety stops.** A run stops at 600 requests, or immediately if the paging
  cursor stops moving forward — results are sorted by id, so each cursor must
  sort after the previous one, which also catches a cursor that cycles back
  (or if the saved run state is unreadable). These are the
  only stops that throw away the run's position, and they **pause automatic
  sync** until you press **Sync now**, which then starts fresh and re-bills
  from page 1 (up to another 600 requests). Sync now asks you to confirm
  first.
- **Lock.** Only one run can be active. A run that has not reported progress
  for 30 minutes is considered crashed and is taken over (resumed). Every
  state write a run makes is checked against the lock, so a run that was
  taken over can never overwrite its successor's progress.

### When a stopped run resumes

| Why the run stopped | Card status | Next automatic attempt | Resumes from its cursor? | Automatic sync |
|---|---|---|---|---|
| Out of credits (402) | `out_of_credits` | 24 hours | yes (fresh key — the 402 was never executed) | resumes after the pause |
| Key rejected / forbidden (401 / 403) | `auth` / `forbidden` | 24 hours, or at once after saving a changed key | yes | resumes after the pause |
| 3 server/network failures in a row | `error` | 1 hour | yes (same key, free replay) | resumes after the pause |
| 10 rate limits (429) in a row | `error` | 1 hour | yes (same key, free replay) | resumes after the pause |
| Key still being processed (409) | `error` | 1 hour | yes (same key) | resumes after the pause |
| Switched off or key removed mid-run | `error` ("disabled mid-run") | 1 hour after the stop; the run resumes at the first hourly tick after BOTH re-enabling and that 1 hour backoff | yes (same key) | resumes once re-enabled and the backoff has passed |
| Internal error in Prismarr | `error` | 6 hours | yes (same key) | resumes after the pause |
| Unreadable response (404, unusable body, …) | `invalid` | never automatically — an unusable 2xx is billed, so retrying it on a timer would re-bill it forever | yes (same key) | **paused until Sync now** |
| HTTP 400 on a page requested with a cursor | `invalid` | never automatically | that phase restarts at its first page (the cursor is the likely culprit) | **paused until Sync now** |
| Paging cursor did not move forward (results are sorted by id, so each cursor must sort after the last) / unreadable run state | `halted` | never automatically | **no** — the next run starts fresh | **paused until Sync now** |
| 600-request cap reached | `request_cap` | never automatically | **no** — the next run starts fresh | **paused until Sync now** |

A **manual** Sync now ignores the pauses above and resumes the interrupted run
at once (including after `invalid`), or, after `halted` / `request_cap`, starts
fresh after a confirmation.

## Scheduling

The messenger worker also consumes a Symfony Scheduler transport
(`scheduler_wokeometer`). It fires an inexpensive tick every hour. The tick
does not call Wokeometer: it only checks the local database to see whether a
sync is due, and if so, queues one. A sync is due when the integration is
enabled, automatic sync is on, automatic sync is not paused by a safety stop,
no retry pause is active, no run is in progress, and either an interrupted run
is waiting to resume or the initial full sync has completed and the last
success is older than 30 days. **Before the initial full sync has completed,
only an interrupted run you started is ever resumed.** The queued job checks
the same conditions again when the worker picks it up.

Because "due" lives in the database rather than in a timer, a container that
was stopped over the due date simply syncs on its next hourly tick after it
comes back. There is no catch-up burst and nothing to reconfigure.

The frequency is fixed at monthly. Untick "Automatic sync" to stop scheduled
runs entirely; the manual buttons keep working.

### Manual "Sync now" and "Full resync"

Both buttons are admin-only, CSRF-protected, and simply queue a job for the
worker; the request itself never calls Wokeometer.

- **Sync now** runs an incremental sync if a full sync has ever completed,
  otherwise the initial full one. If an earlier run was interrupted, it
  continues from where it stopped. After a safety stop (`halted` /
  `request_cap`) it asks for confirmation first, because the new run starts
  from the first page and can bill up to 600 more requests.
- **Full resync** always starts over and re-downloads the whole catalog,
  discarding any interrupted run's position. It asks for confirmation with the
  estimated request count first, because it costs the same as the initial
  sync.
- A manual start **bypasses the automatic pauses** described above (you asked
  explicitly) and clears them; scheduled runs respect them. Neither can start
  while another run holds the lock.

### Worker requirements

- **Single consumer.** The sync is designed for the one built-in worker. Do
  not run a second `messenger:consume async` alongside it: two workers could
  each pick up a continuation of the same run, and duplicate continuation
  chains could double-bill a page.
- **No worker, no sync.** Sync now only queues the run. If no worker is
  running, the card shows "Running…" for up to 30 minutes (the lock's stale
  window) and then "Interrupted — will resume"; the run starts once a worker
  consumes the queue.
- **`MESSENGER_TRANSPORT_DSN=sync://` is unsupported for this feature.** The
  sync handler is bound to the `async` transport, so with a synchronous
  transport the run is never executed.

## Matching

- Movies match on the TMDb id; series match on the TMDb id of the series.
  Wokeometer's list rows do not include a `tmdb_id` field, so Prismarr derives
  it from the row's `external_source` and `external_id` when the source is
  `tmdb` and the id is all digits. No title or year matching is ever done:
  there are no fuzzy matches, so a missing score is always "not in the
  catalog", never "wrongly matched".
- Wokeometer stores each season as its own row. Season rows (any row with a
  parent id or a season number of 1 or more; non-season rows carry `season_number` 0 (movies) or a non-positive sentinel such as -1 (series), which is not a season) are kept locally but are never matched to a
  series (a season's external id can be a TMDb season id that collides with
  an unrelated show), and only the series-level row is shown.
- Sonarr series without a TMDb id do not match and simply show nothing.

## Where it appears

A small "Wokeometer" block (score out of 10, the TL;DR, and a "View full
analysis" link to wokeometer.app; the TL;DR is shown as plain text, with any Markdown emphasis markers stripped, while the stored text stays raw) appears for any title in the Wokeometer
catalog in:

- the global quick-look modal (dashboard, top-bar search, Explorer, Plex
  activity, and anywhere else a media tile opens it),
- the Films and Series detail modals,
- the Discover detail modal.

In each header the score pill reads "Wokeometer 4/10". The block is hidden
entirely when there is no match, when the catalog row has neither a score nor
a TL;DR, or when the integration is disabled or has no key (the Films and
Series pages then do not even query the local endpoint). It is visible to
every signed-in user, because it is read-only local data. The Films and
Series modals fetch it from a small local JSON endpoint that only reads
SQLite; that endpoint never contacts Wokeometer, and neither do the
quick-look and Discover paths.

## Attribution and terms

Scores are shown with attribution, as Wokeometer's terms require: the block is
labelled "Wokeometer", links to the title's public page, and says that the
score is an automated assessment, not a statement of fact. Please treat it
that way: Wokeometer's scores are automated assessments of a title's content,
not verdicts.

Wokeometer's terms allow sharing scores with attribution but not bulk or
commercial reproduction of its analysis, and not redistributing third-party
poster or overview data, which is why those are not stored. Prismarr is not
affiliated with Wokeometer. By using your own key you are bound by Wokeometer's
terms yourself; read them on wokeometer.app.

## Privacy

- The only outbound calls to Wokeometer are the sync's catalog requests
  (`GET /media`), made from the background worker. Opening a page or a modal
  makes none.
- **No library titles, ids or any other information about your library are
  ever sent to Wokeometer.** The requests only ask for the catalog page by
  page; matching happens locally afterwards.
- The API key travels only in the `Authorization` header, never in a URL, a
  log line or a JSON response. Anything key-shaped in a provider error
  message (even a partial echo) is redacted before it is logged or shown.
- The key is stored in the `setting` table like every other API key in
  Prismarr, and is excluded from the Settings export. Leaving the key field
  empty when you save keeps the stored key.
- Prismarr does not add Wokeometer to its health checks, topbar chips or
  "Test connection" buttons: any of those would spend credits.

## Troubleshooting

The "Last run" line on the Settings card shows one of these statuses.

| Status | What it means | What to do |
|---|---|---|
| Never run | No sync has run yet. | Press **Sync now** to run the initial catalog sync (~200 billed requests). Automatic sync then runs incrementally every 30 days. |
| Running | A sync is in progress; the card updates by itself. | Wait. A full sync takes a few minutes because of the 1.2 s pacing. |
| Interrupted — will resume | A run was started but no live worker is holding it (crash, restart, or no worker running). | The next hourly tick resumes it from its cursor, but only while **Automatic sync** is on (otherwise press **Sync now**). Check that the worker is running if it persists. |
| Completed (`ok`) | The last run finished. | Nothing. |
| API key rejected (`auth`) | Wokeometer answered 401. | Paste a valid key. Saving a changed key clears the 24 hour pause and the interrupted run continues where it stopped. |
| Access forbidden (`forbidden`) | Wokeometer answered 403 for this key. | Check the key's permissions on your Wokeometer account, or create a new key. Retried after 24 hours, or immediately after you save a changed key. |
| Out of credits (`out_of_credits`) | Wokeometer answered 402. | Buy credits, then press **Sync now**; the interrupted run continues from its saved cursor instead of re-billing the pages already downloaded. Automatic retry is paused for 24 hours. |
| Rate limited (`rate_limited`) | Wokeometer answered 429. | Normally nothing to do: 429 responses are waited out inside the run; only 10 in a row stop it (as `error`, resumed an hour later). |
| Request cap reached — automatic sync paused (`request_cap`) | The run hit the 600-request safety ceiling. | Unusual for the current catalog size. Check the worker log. **Automatic sync stays paused** until you press **Sync now**, which starts fresh and re-bills from page 1 (up to 600 requests) after a confirmation. |
| Stopped by a safety guard — automatic sync paused (`halted`) | The paging cursor stopped advancing, or the saved run state was unreadable. | Check the worker log for "Wokeometer sync stopped". **Automatic sync stays paused** until you press **Sync now**, which starts fresh and re-bills from page 1 (up to 600 requests) after a confirmation. |
| Failed (`error`) | A resumable stop: three transient failures in a row, ten rate limits in a row, a 409, the integration switched off mid-run, or an internal error. | Check the worker log for "Wokeometer sync stopped". The run resumes from its cursor automatically after 1 hour (transient, rate limits, 409, disabled mid-run — counted from the stop, and only from the first hourly tick after re-enabling) or 6 hours (internal error). Press **Sync now** to resume at once. |
| Unreadable API response (`invalid`) | Wokeometer answered with something Prismarr could not use (a 400/404 or an unparsable body). | **Automatic sync stays paused** (an unusable page is still billed, so it is never retried on a timer). Press **Sync now** to resume from where the run stopped (after a 400 on a later page, that phase restarts at its first page), or use **Full resync** if it keeps failing. |

Other symptoms:

| Symptom | Check |
|---|---|
| The monthly sync never happens | The scheduler tick is consumed by the messenger worker. Look in the worker log for `scheduler_wokeometer` (the worker command is `messenger:consume async scheduler_wokeometer`). Manual **Sync now** works without it. Also confirm "Enabled" and "Automatic sync" are on, that the initial sync has completed, and that the card shows no active pause (`request_cap` / `halted` pause automatic sync until **Sync now**). |
| Nothing happens after saving the key | Expected: saving never starts a billed sync. Press **Sync now**. |
| "Settings saved, but the Wokeometer API key was not" | The key must be `wok_` followed by at least 16 letters or digits; copy it again from your developer page. |
| A sync completed but nothing matches | Look at "Records with a TMDb id" on the card. If it is 0 while "Cached records" is large, Wokeometer is not reporting `tmdb` as the `external_source` (see Known limitations, where the verified behaviour is described); open an issue with the value from the `external_source` column. |
| Scores appear for movies but not for some series | Series without a TMDb id in Sonarr do not match, and Wokeometer may simply not have analyzed them yet. |
| Card shows credits remaining as "—" | Wokeometer reports the balance on each successful response; it appears after the first successful page. |

## Known limitations

- **Deletions are unobservable.** Wokeometer's API does not expose removed
  titles, so a title Wokeometer deletes stays in the local copy until the next
  completed **Full resync**.
- **Matching depends on `external_source`.** List rows do not carry a
  `tmdb_id`, and fetching each title's detail would cost one credit per title,
  so Prismarr derives the TMDb id from `external_source` = `tmdb` and a numeric
  `external_id`. Verified against a full live sync (13,916 rows): every row
  carries `external_source = tmdb` with a numeric `external_id`. The API also
  emits `season_number = 0` on movies and a non-positive sentinel (e.g. -1) on series rows; Prismarr
  treats only a parent id or a positive season number as "season" (0, negatives
  and NULL all mean "not a season").
- **Seasons are stored but not shown.** Only the series-level score is used.
- **Sonarr series without a TMDb id do not match.**
- **Fixed monthly schedule.** The 30-day interval and the 600-request cap are
  constants, not settings.
- **No per-title refresh.** A single title cannot be refreshed on demand; it
  updates with the next incremental sync (or a full resync).
- **One worker only.** See [Worker requirements](#worker-requirements).
