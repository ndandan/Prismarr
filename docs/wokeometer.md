# Wokeometer integration

Prismarr can show a [Wokeometer](https://wokeometer.app) score, a one-line
summary and a link to the full analysis for movies and series you already
have, right inside the detail views. It is optional, off until you paste an
API key, and designed around one rule:

> **Opening a movie or series never calls the Wokeometer API. API calls caused by opening a media detail view: 0.**

Wokeometer's API is prepaid and billed per request, so Prismarr does not look
titles up as you browse. Instead a background job downloads the catalog into
a local SQLite table once, keeps it fresh with a small monthly top-up, and
every detail view reads that local table. Browsing is free and works offline
from Wokeometer's point of view.

```
Wokeometer API  ──(initial full sync, then monthly incremental sync, worker only)──▶  SQLite (wokeometer_media)
                                                                                         │ indexed local lookup by (tmdb_id, media_type)
                                                                                         ▼
                                                        Global quick-look · Films/Series modals · Discover modal
```

## What you need

- A Wokeometer account and a developer API key, created at
  [wokeometer.app/account/developer](https://wokeometer.app/account/developer).
  Keys look like `wok_` followed by 64 hex characters.
- Prepaid API credits. **1 credit = $0.05**, and every successful API request
  costs one credit. Failed requests (4xx/5xx) are not billed. Check the
  pack sizes on Wokeometer's own pricing page: the smallest pack (100 credits,
  $5) is **not** enough for the initial sync; the $50 pack is.
- A running messenger worker. It is part of the standard Prismarr container
  (s6 service `messenger-worker`), so there is nothing to add.

Enable it under **Settings → Services → Metadata enrichment → Wokeometer**:
paste the key, leave "Enabled" and "Automatic sync" on, save, then press
**Sync now**.

## What it costs

| Item | Requests | Approx. cost |
|---|---|---|
| One page of the catalog | 1 request = 1 credit = 50 titles | $0.05 |
| Initial full sync (movies + TV) | about 180-220 | about $9-11 |
| Monthly incremental sync | about 2-60 (whatever changed) | about $0.10-$3 |
| Opening a movie or series | 0 | $0 |
| "Full resync" button | same as the initial sync | about $9-11 |
| Hard ceiling per run (safety cap) | 600 | $30 |

The catalog is currently roughly 2,000 movies and 6,800 TV rows (about 5,200
of those are individual seasons), which is where the 180-220 request estimate
comes from. It grows over time. Every request, including an empty page, costs
one credit, and a run stops by itself at 600 requests.

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

- **Initial full sync.** With no completed full sync on record, a run walks
  the movie catalog and then the TV catalog, 50 titles per request.
- **Monthly incremental sync.** After a full sync has completed, the next run
  is due 30 days after the last success and asks only for titles updated since
  the previous run started, minus a 48 hour overlap so nothing is missed at
  the boundary. Re-seeing a title is harmless: rows are upserted by
  Wokeometer id and never overwritten with older data.
- **Chunked and paced.** A run works in chunks of at most 8 pages, with 1.2
  seconds between pages, then hands the rest to itself as a new job so the
  single background worker keeps serving its other jobs. A rate-limit
  response (HTTP 429) is waited out (using Wokeometer's `Retry-After`, capped
  at 15 minutes); a temporary server or network failure is retried after 30
  seconds, up to 3 failures in a row.
- **Idempotency keys.** Each page request carries an `Idempotency-Key` that is
  saved before the request is sent and reused if the request has to be
  retried or the container restarts mid-page. Wokeometer replays a keyed
  request for free for 24 hours, so a crash never bills the same page twice.
- **The watermark only advances on success.** The "last updated" mark that
  drives the incremental sync is committed only when both the movie and the
  TV phase have finished. An interrupted run resumes from its saved cursor
  instead of starting over.
- **Deletions.** Wokeometer's API does not report removed titles. After a
  completed **full** sync, rows that were not seen during that run are
  removed (unless the run stored nothing at all, which is treated as an API
  glitch and never wipes the local copy). Incremental syncs never delete.
- **Safety stops.** A run stops at 600 requests, or immediately if the paging
  cursor stops advancing, so a misbehaving response cannot drain your credits.
- **Lock.** Only one run can be active. A run that has not reported progress
  for 30 minutes is considered crashed and is taken over (resumed).

## Scheduling

The messenger worker also consumes a Symfony Scheduler transport
(`scheduler_wokeometer`). It fires an inexpensive tick every hour. The tick
does not call Wokeometer: it only checks the local database to see whether a
sync is due (integration enabled, automatic sync on, no active backoff, no run
in progress, and either an unfinished run, no completed sync yet, or the last
success older than 30 days) and, if so, queues one.

Because "due" lives in the database rather than in a timer, a container that
was stopped over the due date simply syncs on its next hourly tick after it
comes back. There is no catch-up burst and nothing to reconfigure.

The frequency is fixed at monthly. Untick "Automatic sync" to stop scheduled
runs entirely; the manual buttons keep working.

### Manual "Sync now" and "Full resync"

Both buttons are admin-only, CSRF-protected, and simply queue a job for the
worker; the request itself never calls Wokeometer.

- **Sync now** runs an incremental sync if a full sync has ever completed,
  otherwise a full one. If an earlier run was interrupted, it continues from
  where it stopped.
- **Full resync** always starts over and re-downloads the whole catalog,
  discarding any interrupted run's position. It asks for confirmation with the
  estimated request count first, because it costs the same as the initial
  sync.
- A manual start **bypasses the automatic backoff** described below (you asked
  explicitly); scheduled runs respect it. Neither can start while another run
  holds the lock.

## Matching

- Movies match on the TMDb id; series match on the TMDb id of the series.
  Wokeometer's list rows do not include a `tmdb_id` field, so Prismarr derives
  it from the row's `external_source` and `external_id` when the source is
  `tmdb` and the id is all digits. No title or year matching is ever done:
  there are no fuzzy matches, so a missing score is always "not in the
  catalog", never "wrongly matched".
- Wokeometer stores each season as its own row. Season rows are kept locally
  but are never matched to a series (a season's external id can be a TMDb
  season id that collides with an unrelated show), and only the series-level
  row is shown.
- Sonarr series without a TMDb id do not match and simply show nothing.

## Where it appears

A small "Wokeometer" block (score out of 10, the TL;DR, and a "View full
analysis" link to wokeometer.app) appears in:

- the global quick-look modal (dashboard, top-bar search, Explorer, Plex
  activity, and anywhere else a media tile opens it),
- the Films and Series detail modals,
- the Discover detail modal.

It is hidden entirely when there is no match, or when the integration is
disabled or has no key. It is visible to every signed-in user, because it is
read-only local data. The Films and Series modals fetch it from a small local
JSON endpoint that only reads SQLite; that endpoint never contacts Wokeometer,
and neither do the quick-look and Discover paths.

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
  log line or a JSON response.
- The key is stored in the `setting` table like every other API key in
  Prismarr, and is excluded from the Settings export. Leaving the key field
  empty when you save keeps the stored key.
- Prismarr does not add Wokeometer to its health checks, topbar chips or
  "Test connection" buttons: any of those would spend credits.

## Troubleshooting

The "Last run" line on the Settings card shows one of these statuses.

| Status | What it means | What to do |
|---|---|---|
| Never run | No sync has run yet. | Save the key and press **Sync now**. |
| Running | A sync is in progress; the card updates by itself. | Wait. A full sync takes a few minutes because of the 1.2 s pacing. |
| Completed (`ok`) | The last run finished. | Nothing. |
| API key rejected (`auth`) | Wokeometer answered 401. | Paste a valid key. Saving a changed key clears the 24 hour pause and the interrupted run continues where it stopped. |
| Access forbidden (`forbidden`) | Wokeometer answered 403 for this key. | Check the key's permissions on your Wokeometer account, or create a new key. Retried after 24 hours, or immediately after you save a changed key. |
| Out of credits (`out_of_credits`) | Wokeometer answered 402. | Buy credits, then press **Sync now**; the interrupted run continues from its saved cursor instead of re-billing the pages already downloaded. Automatic retry is paused for 24 hours. |
| Rate limited (`rate_limited`) | Wokeometer answered 429. | Normally nothing to do: 429 responses are waited out inside the run and do not stop it, so this status is not expected to persist. |
| Request cap reached (`request_cap`) | The run hit the 600-request safety ceiling. | Unusual for the current catalog size. Check the worker log, then press **Sync now**; the next run starts fresh. Automatic retry is paused for 24 hours. |
| Failed (`error`) | Three transient failures in a row, a run that could not continue (for example the integration was disabled mid-run), an internal error, or a paging cursor that stopped advancing. | Check the worker log for "Wokeometer sync stopped". Automatic retries follow after 1 hour (transient), 6 hours (internal error) or 24 hours (cursor or phase fault). Press **Sync now** to retry at once. |
| Unreadable API response (`invalid`) | Wokeometer answered with something Prismarr could not use (a 400/404 or an unparsable body). | Retried after 7 days; press **Sync now** to retry sooner, or use **Full resync** if it keeps failing. |

Other symptoms:

| Symptom | Check |
|---|---|
| The monthly sync never happens | The scheduler tick is consumed by the messenger worker. Look in the worker log for `scheduler_wokeometer` (the worker command is `messenger:consume async scheduler_wokeometer`). Manual **Sync now** works without it. Also confirm "Enabled" and "Automatic sync" are on and the card shows no active pause. |
| A sync completed but nothing matches | Look at "Records with a TMDb id" on the card. If it is 0 while "Cached records" is large, Wokeometer is not reporting `tmdb` as the `external_source` (see Known limitations); open an issue with the value from the `external_source` column. |
| Scores appear for movies but not for some series | Series without a TMDb id in Sonarr do not match, and Wokeometer may simply not have analyzed them yet. |
| Card shows credits remaining as "—" | Wokeometer reports the balance on each successful response; it appears after the first successful page. |

## Known limitations

- **Deletions are unobservable.** Wokeometer's API does not expose removed
  titles, so a title Wokeometer deletes stays in the local copy until the next
  completed **Full resync**.
- **Matching depends on `external_source`.** List rows do not carry a
  `tmdb_id`, and fetching each title's detail would cost one credit per title,
  so Prismarr derives the TMDb id from `external_source` = `tmdb` and a numeric
  `external_id`. This was inferred from the API, not confirmed against a large
  live sync: after your first sync, check that "Records with a TMDb id" is
  close to the number of cached movies plus series. If it is not, matching
  will be poor and the value reported by Wokeometer needs a code change.
- **Seasons are stored but not shown.** Only the series-level score is used.
- **Sonarr series without a TMDb id do not match.**
- **Fixed monthly schedule.** The 30-day interval and the 600-request cap are
  constants, not settings.
- **No per-title refresh.** A single title cannot be refreshed on demand; it
  updates with the next incremental sync (or a full resync).
