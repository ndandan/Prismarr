# Tautulli Activity Page Enhancements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a stream-summary strip, a Recently Added section, and live-card polish (HDR/SDR badge + codec-transition transcode detail) to the existing Tautulli "Plex Activity" page.

**Architecture:** Reuse the shipped pattern — server-rendered HTML fragments hydrated by a scoped IIFE, the server-side image proxy, strict allow-list normalizers, and fail-open endpoints. The summary strip is a DRY extraction of markup the dashboard widget already renders. Recently Added is a new read-only `get_recently_added` call + fragment. Card polish adds allow-listed fields to `normalizeSession` and enriches the shared card partial (so it improves both the page and the dashboard widget).

**Tech Stack:** Symfony 6 (PHP), Twig, PHPUnit, vanilla JS, Tautulli API v2.

**Spec:** `docs/superpowers/specs/2026-06-16-tautulli-activity-enhancements-design.md`

---

## ⚠️ Environment constraint (read first)

This Windows box has **no local PHP/Docker**. Do **NOT** run `phpunit`, `docker exec`, or `make` locally — those commands are unavailable and will fail. The TDD "run the test" steps below are therefore written as **author the failing test → author the implementation → verification deferred to CI**. CI's `make check` (PHPUnit + `lint:twig` + `lint:yaml`) runs on push in the final task and is the source of truth for green tests. Use the **PowerShell tool** for git.

Commit trailer for every commit:
```
-m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## File Structure

**Create:**
- `symfony/templates/dashboard/_plex_summary.html.twig` — shared stream-summary strip (streams + decision badges + bandwidth). Extracted from `_plex_activity.html.twig`; included by both the dashboard widget and the Tautulli page.
- `symfony/templates/tautulli/_recently_added.html.twig` — Recently Added list fragment (clickable rows, no timestamps).

**Modify:**
- `symfony/templates/dashboard/_plex_activity.html.twig` — replace the inline `.plex-summary` block with an include of the new partial.
- `symfony/templates/tautulli/_now_playing.html.twig` — render the summary strip above the cards.
- `symfony/src/Service/Media/TautulliClient.php` — add `getRecentlyAdded()` + `normalizeRecentlyAdded()`; extend `normalizeSession()` with `dynamicRange` + codec fields.
- `symfony/tests/Service/Media/TautulliClientTest.php` — tests for the two normalizer additions.
- `symfony/src/Controller/TautulliController.php` — add the `app_tautulli_api_recently_added` endpoint; update the class docblock.
- `symfony/templates/tautulli/index.html.twig` — add the Recently Added section + JS hydration.
- `symfony/templates/dashboard/_plex_session_card.html.twig` — HDR/SDR badge + codec-transition transcode detail.
- `symfony/translations/messages+intl-icu.en.yaml` and `…fr.yaml` — Recently Added keys.
- `CHANGELOG.md`, `docs/FORK-CHANGES.md` — record the enhancements.

**Note on existing i18n:** the summary-strip keys already exist (`dashboard.plex.streams`, `dashboard.plex.summary.*`, `dashboard.plex.bandwidth.*`, `dashboard.plex.mbps`) and the `.plex-summary` CSS already lives in `dashboard/_plex_styles.html.twig` (included on both pages). So Component A needs **no new i18n and no new CSS**. The card-polish track labels (`dashboard.plex.track.video/audio/subtitle`) also already exist.

---

## Task 1: Stream summary strip (Component A — DRY extraction)

The dashboard widget (`_plex_activity.html.twig`) already renders the exact strip we want. Extract it into a shared partial and include it on the Tautulli page's now-playing fragment. Pure-template change; verified by `lint:twig` (CI) + manual check.

**Files:**
- Create: `symfony/templates/dashboard/_plex_summary.html.twig`
- Modify: `symfony/templates/dashboard/_plex_activity.html.twig:23-39`
- Modify: `symfony/templates/tautulli/_now_playing.html.twig`

- [ ] **Step 1: Create the shared summary partial**

Create `symfony/templates/dashboard/_plex_summary.html.twig` with the markup currently inlined in the widget (verbatim, so the widget is unchanged):

```twig
{# Stream-summary strip: stream count + Direct Play/Stream/Transcode badges +
   total/LAN/WAN bandwidth. `plex` is the getActivity() envelope. Shared by the
   dashboard widget (_plex_activity) and the Tautulli page (_now_playing). The
   .plex-summary CSS lives in dashboard/_plex_styles.html.twig (loaded on both). #}
{% import '_icons.html.twig' as ico %}
<div class="plex-summary">
  <div class="plex-stat">
    <div class="plex-stat-value">{{ plex.streamCount }}</div>
    <div class="plex-stat-label">{{ 'dashboard.plex.streams'|trans }}</div>
  </div>
  <div class="plex-stat-badges">
    {% if plex.directPlayCount > 0 %}<span class="badge bg-green-lt text-green">{{ 'dashboard.plex.summary.direct_play'|trans({count: plex.directPlayCount}) }}</span>{% endif %}
    {% if plex.directStreamCount > 0 %}<span class="badge bg-azure-lt text-azure">{{ 'dashboard.plex.summary.direct_stream'|trans({count: plex.directStreamCount}) }}</span>{% endif %}
    {% if plex.transcodeCount > 0 %}<span class="badge bg-orange-lt text-orange">{{ 'dashboard.plex.summary.transcode'|trans({count: plex.transcodeCount}) }}</span>{% endif %}
  </div>
  <div class="plex-bw">
    <span title="{{ 'dashboard.plex.bandwidth.total'|trans }}">{{ ico.icon('activity', '', 14) }} {{ plex.bandwidth.totalMbps }} {{ 'dashboard.plex.mbps'|trans }}</span>
    <span class="text-secondary">{{ 'dashboard.plex.bandwidth.lan'|trans }} {{ plex.bandwidth.lanMbps }}</span>
    <span class="text-secondary">{{ 'dashboard.plex.bandwidth.wan'|trans }} {{ plex.bandwidth.wanMbps }}</span>
  </div>
</div>
```

- [ ] **Step 2: Replace the inline block in the dashboard widget with an include**

In `symfony/templates/dashboard/_plex_activity.html.twig`, replace the whole `{# ── Summary … ──#}` block (the `<div class="plex-summary">…</div>`, currently lines 23–39) with:

```twig
  {% include 'dashboard/_plex_summary.html.twig' with {plex: plex} only %}
```

Leave the surrounding `{% else %}` / `<div class="plex-sessions">` loop untouched.

- [ ] **Step 3: Render the strip on the Tautulli page now-playing fragment**

Replace the contents of `symfony/templates/tautulli/_now_playing.html.twig` with (adds the strip above the cards in the connected, non-empty branch; the empty/error branch is unchanged):

```twig
{# Live stream cards for the activity page. `plex` is the getActivity() envelope. #}
{% if plex.error or not plex.connected or plex.sessions is empty %}
  <div class="text-center text-secondary py-4">{{ 'dashboard.plex.empty'|trans }}</div>
{% else %}
  {% include 'dashboard/_plex_summary.html.twig' with {plex: plex} only %}
  <div class="plex-sessions">
    {% for s in plex.sessions %}
      {% include 'dashboard/_plex_session_card.html.twig' with {s: s} only %}
    {% endfor %}
  </div>
{% endif %}
```

The strip only renders in the connected + non-empty branch, so the `apiNowPlaying` error fallback (which omits `streamCount`/`bandwidth`) never touches those keys. No controller change needed: `index()` and `apiNowPlaying()` already pass the full `getActivity()` envelope.

- [ ] **Step 4: Commit**

```bash
git add symfony/templates/dashboard/_plex_summary.html.twig symfony/templates/dashboard/_plex_activity.html.twig symfony/templates/tautulli/_now_playing.html.twig
git commit -m "feat(tautulli): show stream-summary strip on the activity page" -m "Extract the dashboard widget's stream-summary block into a shared partial and render it above Now Playing on the Tautulli page." -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Recently Added — client method + normalizer (TDD)

**Files:**
- Modify: `symfony/src/Service/Media/TautulliClient.php`
- Test: `symfony/tests/Service/Media/TautulliClientTest.php`

- [ ] **Step 1: Write the failing tests**

Add to `symfony/tests/Service/Media/TautulliClientTest.php` (after the existing tests, before the final `}`):

```php
    /** A representative get_recently_added `data` object (movie + episode). */
    private function recentlyAddedFixture(): array
    {
        return [
            'recently_added' => [
                [
                    'rating_key'        => '54321',
                    'media_type'        => 'movie',
                    'title'             => 'The Wild Robot',
                    'year'              => '2024',
                    'thumb'             => '/library/metadata/54321/thumb/1700000001',
                    // private fields that must be stripped:
                    'section_id'        => '1',
                    'guid'              => 'plex://movie/wildrobot',
                    'file'              => '/data/media/movies/WildRobot.mkv',
                ],
                [
                    'rating_key'        => '67890',
                    'media_type'        => 'episode',
                    'title'             => 'Pilot',
                    'grandparent_title' => 'Severance',
                    'year'              => '2025',
                    'thumb'             => '/library/metadata/67890/thumb/1',      // landscape still
                    'grandparent_thumb' => '/library/metadata/600/thumb/2',       // portrait series art
                ],
            ],
        ];
    }

    public function testNormalizeRecentlyAddedMapsFields(): void
    {
        $out = TautulliClient::normalizeRecentlyAdded($this->recentlyAddedFixture());

        self::assertCount(2, $out['items']);

        $movie = $out['items'][0];
        self::assertSame('54321', $movie['ratingKey']);
        self::assertSame('movie', $movie['mediaType']);
        self::assertSame('The Wild Robot', $movie['title']);
        self::assertSame('2024', $movie['year']);
        self::assertSame('/library/metadata/54321/thumb/1700000001', $movie['posterPath']);

        $episode = $out['items'][1];
        self::assertSame('episode', $episode['mediaType']);
        self::assertSame('Severance', $episode['grandparentTitle']);
        // Episodes resolve to the portrait series art, not the landscape still.
        self::assertSame('/library/metadata/600/thumb/2', $episode['posterPath']);
    }

    public function testNormalizeRecentlyAddedStripsPrivateFields(): void
    {
        $out = TautulliClient::normalizeRecentlyAdded($this->recentlyAddedFixture());
        foreach ($out['items'] as $item) {
            foreach (['section_id', 'guid', 'file'] as $forbidden) {
                self::assertArrayNotHasKey($forbidden, $item, "private field {$forbidden} leaked");
            }
            $flat = implode('|', array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', $item));
            self::assertStringNotContainsString('plex://', $flat);
            self::assertStringNotContainsString('/data/media', $flat);
        }
    }

    public function testNormalizeRecentlyAddedEmpty(): void
    {
        self::assertSame(['items' => []], TautulliClient::normalizeRecentlyAdded([]));
    }
```

- [ ] **Step 2: (Verification deferred to CI)**

Do not run PHPUnit locally (no PHP on this box). The new tests reference `TautulliClient::normalizeRecentlyAdded()`, which does not exist yet — they would error with "undefined method" until Step 3. CI's `make check` (Task 7) is the verification gate.

- [ ] **Step 3: Implement `getRecentlyAdded` + `normalizeRecentlyAdded`**

In `symfony/src/Service/Media/TautulliClient.php`, add these methods (place after `normalizeLibraries`, mirroring the `getLibraries`/`normalizeLibraries` pattern):

```php
    /**
     * Most recently added Plex items, normalized + sanitized. Returns a single
     * `items` list (newest first, as Tautulli returns them); an empty list
     * covers disabled/unconfigured/unreachable so the section shows its empty
     * state. `count` is clamped to 1..50 before reaching Tautulli.
     *
     * @return array{items:list<array{ratingKey:?string,title:?string,year:?string,posterPath:?string,mediaType:?string,grandparentTitle:?string}>}
     */
    public function getRecentlyAdded(int $count = 10): array
    {
        $this->ensureConfig();
        if (!$this->enabled || $this->baseUrl === '' || $this->apiKey === '') {
            return self::normalizeRecentlyAdded([]);
        }
        $resp = $this->request([
            'cmd'   => 'get_recently_added',
            'count' => (string) max(1, min(50, $count)),
        ]);
        if ($resp === null || $resp['ok'] !== true) {
            return self::normalizeRecentlyAdded([]);
        }
        return self::normalizeRecentlyAdded(is_array($resp['data']) ? $resp['data'] : []);
    }

    /**
     * Pure transform: get_recently_added `data` → sanitized {items}. Allow-list
     * only — section ids, guids, file paths, added_at host fields are never
     * copied out (we intentionally omit timestamps).
     *
     * @param array<string, mixed> $data
     * @return array{items:list<array{ratingKey:?string,title:?string,year:?string,posterPath:?string,mediaType:?string,grandparentTitle:?string}>}
     */
    public static function normalizeRecentlyAdded(array $data): array
    {
        $rows = is_array($data['recently_added'] ?? null) ? $data['recently_added'] : [];
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $mediaType = self::str($r['media_type'] ?? null);
            $out[] = [
                'ratingKey'        => self::str($r['rating_key'] ?? null),
                'title'            => self::str($r['title'] ?? null),
                'year'             => self::str($r['year'] ?? null),
                'posterPath'       => self::pickPoster($r, $mediaType),
                'mediaType'        => $mediaType,
                'grandparentTitle' => self::str($r['grandparent_title'] ?? null),
            ];
        }
        return ['items' => $out];
    }
```

- [ ] **Step 4: Commit**

```bash
git add symfony/src/Service/Media/TautulliClient.php symfony/tests/Service/Media/TautulliClientTest.php
git commit -m "feat(tautulli): add getRecentlyAdded client method + normalizer" -m "Read-only get_recently_added wrapper with a strict allow-list normalizer (count clamped 1..50; no ids/guids/paths/timestamps)." -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Recently Added — controller endpoint + fragment template

**Files:**
- Modify: `symfony/src/Controller/TautulliController.php`
- Create: `symfony/templates/tautulli/_recently_added.html.twig`

- [ ] **Step 1: Add the endpoint**

In `symfony/src/Controller/TautulliController.php`, add this action after `apiLibraries()` (same fail-open shape as the other fragment endpoints; the `app_tautulli_api_*` name keeps it unguarded):

```php
    /** GET /tautulli/api/recently-added — recently added items fragment. */
    #[Route('/api/recently-added', name: 'api_recently_added', methods: ['GET'])]
    public function apiRecentlyAdded(): Response
    {
        try {
            $recent = $this->tautulli->getRecentlyAdded(10);
        } catch (\Throwable) {
            $recent = ['items' => []];
        }
        return $this->render('tautulli/_recently_added.html.twig', ['recent' => $recent]);
    }
```

- [ ] **Step 2: Update the class docblock**

In the same file, extend the read-only command list in the class docblock (currently lists `get_metadata, get_history, get_home_stats, get_plays_by_date, get_libraries`) to include `get_recently_added`:

```php
     * The page also exposes read-only `get_metadata`, `get_history`,
     * `get_home_stats`, `get_plays_by_date`, `get_libraries`, and
     * `get_recently_added` — no terminate_session, notifications or any other
     * mutating Tautulli command.
```

- [ ] **Step 3: Create the fragment template**

Create `symfony/templates/tautulli/_recently_added.html.twig` (self-contained rows — poster + title + clickable modal trigger, no user/time meta, no progress bar):

```twig
{# Recently added Plex items. `recent` = {items: [{ratingKey, title, year,
   posterPath, mediaType, grandparentTitle}]}. Each row opens the info modal via
   the shared delegated handler (data-plex-rating-key). No timestamps by design. #}
{% import '_icons.html.twig' as ico %}
{% if recent.items is empty %}
  <div class="text-center text-secondary py-4">{{ 'tautulli.recently_added.empty'|trans }}</div>
{% else %}
  <div class="plex-sessions">
    {% for it in recent.items %}
      <div class="plex-session {% if it.ratingKey %}plex-clickable{% endif %}"
           {% if it.ratingKey %}data-plex-rating-key="{{ it.ratingKey }}" role="button" tabindex="0"{% endif %}>
        <div class="plex-poster">
          {{ ico.icon(it.mediaType == 'movie' ? 'movie' : 'device-tv', '', 22) }}
          {% if it.posterPath %}<img class="plex-poster-img" src="{{ path('app_tautulli_api_image', {img: it.posterPath}) }}" alt="" loading="lazy">{% endif %}
        </div>
        <div class="plex-session-main">
          <div class="plex-session-title text-truncate">
            {% if it.mediaType == 'episode' and it.grandparentTitle %}{{ it.grandparentTitle }}{% else %}{{ it.title }}{% endif %}
            {% if it.year %}<span class="plex-session-year">({{ it.year }})</span>{% endif %}
          </div>
        </div>
      </div>
    {% endfor %}
  </div>
{% endif %}
```

- [ ] **Step 4: Commit**

```bash
git add symfony/src/Controller/TautulliController.php symfony/templates/tautulli/_recently_added.html.twig
git commit -m "feat(tautulli): add recently-added fragment endpoint + template" -m "Fail-open GET /tautulli/api/recently-added rendering clickable rows that open the existing info modal." -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Recently Added — page section + JS hydration + i18n

**Files:**
- Modify: `symfony/templates/tautulli/index.html.twig`
- Modify: `symfony/translations/messages+intl-icu.en.yaml`
- Modify: `symfony/translations/messages+intl-icu.fr.yaml`

- [ ] **Step 1: Add the section to the page**

In `symfony/templates/tautulli/index.html.twig`, add a new card after the History/Libraries row (after the `</div>` closing the `row row-cards mb-3` block at line ~81, still inside `container-xl`):

```twig
    {# 5. Recently Added #}
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">{{ 'tautulli.section.recently_added'|trans }}</h3></div>
      <div class="card-body plex-sessions" data-tautulli-recently-added>
        <div class="text-center text-secondary py-4"><span class="spinner-border"></span></div>
      </div>
    </div>
```

- [ ] **Step 2: Add the JS hydration**

In the same file's `<script>` IIFE, add a loader next to `refreshLibraries` (after line ~122):

```javascript
  // Recently Added — once.
  function refreshRecentlyAdded() {
    loadFragment('/tautulli/api/recently-added', frag('[data-tautulli-recently-added]'));
  }
```

Then call it in the initial-hydration block (next to `refreshLibraries();`, after line ~201):

```javascript
  refreshLibraries();
  refreshRecentlyAdded();
```

- [ ] **Step 3: Add i18n keys (en)**

In `symfony/translations/messages+intl-icu.en.yaml`, under `tautulli:`: add `recently_added` to the `section:` map and a new `recently_added:` block. Final shape:

```yaml
  section:
    now_playing: Now playing
    stats: Statistics
    plays: Plays over time
    history: History
    libraries: Libraries
    recently_added: Recently added
  ...
  recently_added:
    empty: Nothing added recently
```

(Place `recently_added:` as a sibling of `libraries:` under `tautulli:`.)

- [ ] **Step 4: Add i18n keys (fr)**

In `symfony/translations/messages+intl-icu.fr.yaml`, mirror the same keys with French values:

```yaml
  section:
    ...
    recently_added: Ajouts récents
  ...
  recently_added:
    empty: Aucun ajout récent
```

(Match the exact nesting used by the existing `tautulli:` block in the fr file.)

- [ ] **Step 5: Commit**

```bash
git add symfony/templates/tautulli/index.html.twig symfony/translations/messages+intl-icu.en.yaml symfony/translations/messages+intl-icu.fr.yaml
git commit -m "feat(tautulli): wire Recently Added section into the activity page" -m "New stacked section hydrated once on load, with en/fr strings." -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: Live-card polish — normalizer fields (TDD)

**Files:**
- Modify: `symfony/src/Service/Media/TautulliClient.php` (`normalizeSession`)
- Test: `symfony/tests/Service/Media/TautulliClientTest.php`

- [ ] **Step 1: Write the failing test**

Add to `symfony/tests/Service/Media/TautulliClientTest.php`:

```php
    public function testNormalizesDynamicRangeAndTranscodeCodecs(): void
    {
        $data = $this->fixtureData();
        $data['sessions'][0]['transcode_decision']        = 'transcode';
        $data['sessions'][0]['video_decision']            = 'transcode';
        $data['sessions'][0]['audio_decision']            = 'transcode';
        $data['sessions'][0]['video_codec']               = 'hevc';
        $data['sessions'][0]['stream_video_codec']        = 'h264';
        $data['sessions'][0]['audio_codec']               = 'truehd';
        $data['sessions'][0]['stream_audio_codec']        = 'aac';
        $data['sessions'][0]['video_dynamic_range']       = 'HDR';
        $data['sessions'][0]['stream_video_dynamic_range']= 'SDR';

        $s = TautulliClient::normalizeActivity($data)['sessions'][0];

        self::assertSame('SDR', $s['dynamicRange']); // stream value preferred
        self::assertSame('hevc', $s['videoCodec']);
        self::assertSame('h264', $s['streamVideoCodec']);
        self::assertSame('truehd', $s['audioCodec']);
        self::assertSame('aac', $s['streamAudioCodec']);

        // Still no private fields after growing the allow-list.
        foreach (['ip_address', 'machine_id', 'session_token', 'file', 'email', 'username'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $s);
        }
    }
```

- [ ] **Step 2: (Verification deferred to CI)**

Do not run PHPUnit locally. The assertions reference `dynamicRange`/`videoCodec`/etc., which `normalizeSession` does not yet emit — fails until Step 3. CI verifies in Task 7.

- [ ] **Step 3: Add the fields to `normalizeSession`**

In `symfony/src/Service/Media/TautulliClient.php`, inside `normalizeSession()`'s returned array, add these entries after `'subtitleDecision' => …` (keep the existing keys):

```php
            // Dynamic range badge (HDR/SDR/Dolby Vision). Prefer the actual
            // stream value over the source so a transcoded-to-SDR stream reads
            // SDR. Display-only label — no sensitive surface.
            'dynamicRange'     => self::str($s['stream_video_dynamic_range'] ?? ($s['video_dynamic_range'] ?? null)),
            // Source vs stream codecs, for the "HEVC → H264" transcode detail.
            'videoCodec'       => self::str($s['video_codec'] ?? null),
            'streamVideoCodec' => self::str($s['stream_video_codec'] ?? null),
            'audioCodec'       => self::str($s['audio_codec'] ?? null),
            'streamAudioCodec' => self::str($s['stream_audio_codec'] ?? null),
```

- [ ] **Step 4: Commit**

```bash
git add symfony/src/Service/Media/TautulliClient.php symfony/tests/Service/Media/TautulliClientTest.php
git commit -m "feat(tautulli): expose dynamic-range + codec fields on sessions" -m "Allow-list stream/source video+audio codecs and dynamic range for the card HDR badge and codec-transition transcode detail." -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: Live-card polish — card template (HDR/SDR + codec transitions)

**Files:**
- Modify: `symfony/templates/dashboard/_plex_session_card.html.twig`

This partial is shared, so the polish appears on both the Tautulli page and the dashboard widget. Verified by `lint:twig` (CI) + manual check.

- [ ] **Step 1: Add the HDR/SDR badge**

In `symfony/templates/dashboard/_plex_session_card.html.twig`, in the `<div class="plex-badges">` block, add the dynamic-range badge after the existing `quality` badge (before the bandwidth badge):

```twig
      {% if s.quality %}<span class="badge bg-secondary-lt">{{ s.quality }}</span>{% endif %}
      {% if s.dynamicRange %}<span class="badge bg-secondary-lt text-uppercase">{{ s.dynamicRange }}</span>{% endif %}
      {% if s.bandwidthMbps > 0 %}<span class="badge bg-secondary-lt">{{ s.bandwidthMbps }} {{ 'dashboard.plex.mbps'|trans }}</span>{% endif %}
```

- [ ] **Step 2: Rework the transcode detail to show codec transitions**

Replace the existing transcode block (currently `{% if dec == 'transcode' %}<div class="plex-decisions text-secondary">…</div>{% endif %}`) with:

```twig
    {% if dec == 'transcode' %}
      {% set v_trans = (s.videoDecision|default('')|lower == 'transcode') and s.videoCodec and s.streamVideoCodec and (s.videoCodec|lower != s.streamVideoCodec|lower) %}
      {% set a_trans = (s.audioDecision|default('')|lower == 'transcode') and s.audioCodec and s.streamAudioCodec and (s.audioCodec|lower != s.streamAudioCodec|lower) %}
      <div class="plex-decisions text-secondary">
        {{ 'dashboard.plex.track.video'|trans }}
        {% if v_trans %}{{ s.videoCodec|upper }} → {{ s.streamVideoCodec|upper }}{% else %}{{ s.videoDecision ?? '—' }}{% endif %} ·
        {{ 'dashboard.plex.track.audio'|trans }}
        {% if a_trans %}{{ s.audioCodec|upper }} → {{ s.streamAudioCodec|upper }}{% else %}{{ s.audioDecision ?? '—' }}{% endif %} ·
        {{ 'dashboard.plex.track.subtitle'|trans }} {{ s.subtitleDecision ?? '—' }}
      </div>
    {% endif %}
```

When a track is transcoding and both codecs are known and differ, it shows `HEVC → H264`; otherwise it falls back to the existing decision word. Subtitles keep the decision word (no clean source/target codec pair). No new i18n — the `track.*` labels already exist.

- [ ] **Step 3: Commit**

```bash
git add symfony/templates/dashboard/_plex_session_card.html.twig
git commit -m "feat(tautulli): add HDR/SDR badge + codec-transition transcode detail" -m "Stream cards (page + dashboard widget) now show the dynamic range and, when transcoding, the source→target codec instead of just the decision word." -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: Docs, CHANGELOG, and CI verification

**Files:**
- Modify: `CHANGELOG.md`
- Modify: `docs/FORK-CHANGES.md`

- [ ] **Step 1: Update CHANGELOG**

In `CHANGELOG.md`, under the unreleased section, extend the Tautulli entry (match the existing list style) with:

```markdown
- Tautulli activity page: stream-summary strip (session count, Direct Play / Direct Stream / Transcode breakdown, total/LAN/WAN bandwidth), a Recently Added section, and richer stream cards (HDR/SDR badge + source→target codec on transcoding streams).
```

- [ ] **Step 2: Update docs/FORK-CHANGES.md**

In `docs/FORK-CHANGES.md`, add a bullet under the Tautulli fork-changes area describing the same three enhancements (match the file's existing wording/structure).

- [ ] **Step 3: Commit**

```bash
git add CHANGELOG.md docs/FORK-CHANGES.md
git commit -m "docs: changelog + fork-changes for Tautulli activity enhancements" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

- [ ] **Step 4: Push and verify CI is green**

Push to `origin/main` (this is outward-facing — triggers CI + GHCR image rebuild; only push with user go-ahead):

```bash
git push origin main
```

Then watch CI's `make check` (PHPUnit + `lint:twig` + `lint:yaml`). **This is the verification gate for all the TDD tests authored in Tasks 2 and 5.** If anything is red, fix forward (new commits) until green. Confirm the GHCR `:latest` image rebuilds.

- [ ] **Step 5: Manual verification on Unraid (after image rebuild)**

After Unraid pulls the rebuilt image (Force Update), verify on the live instance:
- Summary strip shows correct session count, decision breakdown, and bandwidth above Now Playing; it disappears when there are no active streams.
- A transcoding stream shows `Video: <src> → <dst>` (and audio likewise); a direct-play stream shows no transcode line.
- HDR/SDR badge appears on streams that report a dynamic range.
- Recently Added lists the latest 10 items; each opens the info modal; the empty state shows when nothing is configured/available.
- Everything fails open: with Tautulli stopped, the page still renders with empty sections (no 500).

---

## Self-Review Notes

- **Spec coverage:** Component A → Task 1; Component B → Tasks 2–4; Component C → Tasks 5–6; security/fail-open is inherited (no new sensitive fields; new endpoint mirrors existing fail-open shape); testing/docs/rollout → Tasks 2/5 (unit), 7 (CI + docs + manual). All spec sections map to a task.
- **Type consistency:** `getRecentlyAdded`/`normalizeRecentlyAdded` return `{items: [...]}` everywhere (controller passes `recent`, template reads `recent.items`). Session fields `dynamicRange`, `videoCodec`, `streamVideoCodec`, `audioCodec`, `streamAudioCodec` are named identically in the normalizer (Task 5), the test (Task 5), and the card template (Task 6). The summary partial reads `plex.streamCount`/`directPlayCount`/`directStreamCount`/`transcodeCount`/`bandwidth.*`, all already present on the `getActivity()` envelope.
- **No placeholders:** every code step contains complete, copy-ready content.
