# Plex Activity: Info Modal, Live Pill & Recently-Watched — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a clickable info modal, a sidebar "streaming" pill, and a "Recently watched" tab to the existing Tautulli/Plex-activity dashboard widget.

**Architecture:** Server-rendered fragments + small delegated JS. New `TautulliClient` methods (`getMetadata`, `getHistory`) return allow-listed, sanitized shapes via pure static normalizers (unit-tested). A new metadata route renders a modal body; the dashboard widget gains tabs and reuses the modal; the sidebar pill hydrates off the existing `/tautulli/api/activity` endpoint. Same security model as the rest of the integration: API key stays server-side, fails open.

**Tech Stack:** Symfony 7 (PHP 8.4), Twig, FrankenPHP, PHPUnit 13, Tabler/Bootstrap, vanilla JS. Tests run in-container via `make check` (PHP lint + Twig lint + PHPUnit); CI gates `main`; GHCR republishes `:latest`.

**Note on running tests:** there is no local PHP toolchain. Run PHPUnit inside the container: `docker exec -e APP_ENV=test prismarr vendor/bin/phpunit --filter <name>`, or rely on CI (`make check`) after each commit. Commands below show the phpunit filter to use.

---

### Task 1: `TautulliClient::getMetadata` + `normalizeMetadata` + keep `ratingKey`

**Files:**
- Modify: `symfony/src/Service/Media/TautulliClient.php`
- Test: `symfony/tests/Service/Media/TautulliClientTest.php`

- [ ] **Step 1: Write the failing tests**

Add to `TautulliClientTest.php` (before the final closing `}`):

```php
    /** A representative get_metadata `data` object for a movie. */
    private function metadataMovieFixture(): array
    {
        return [
            'rating_key'     => '12345',
            'media_type'     => 'movie',
            'title'          => 'See How They Run',
            'year'           => '2022',
            'summary'        => 'In 1950s London, plans for a movie adaptation grind to a halt.',
            'tagline'        => 'Watch your step.',
            'content_rating' => 'PG-13',
            'duration'       => '5820000',
            'rating'         => '7.5',
            'audience_rating'=> '8.1',
            'studio'         => 'Searchlight Pictures',
            'originally_available_at' => '2022-09-16',
            'genres'         => ['Comedy', 'Crime', 'Mystery'],
            'directors'      => ['Tom George'],
            'writers'        => ['Mark Chappell'],
            'actors'         => ['Sam Rockwell', 'Saoirse Ronan', 'Adrien Brody', 'Ruth Wilson',
                                 'Reece Shearsmith', 'Harris Dickinson', 'David Oyelowo', 'Pippa Bennett-Warner',
                                 'Extra Person Nine'],
            'thumb'          => '/library/metadata/12345/thumb/1700000000',
            'media_info'     => [[
                'container'            => 'mkv',
                'bitrate'              => '8700',
                'video_codec'          => 'hevc',
                'audio_codec'          => 'eac3',
                'video_full_resolution'=> '1080p',
            ]],
            // ── Private fields that must be stripped ──────────────
            'file'           => '/data/media/movies/SeeHowTheyRun.mkv',
            'section_id'     => '1',
            'guid'           => 'plex://movie/abc',
            'live'           => 0,
        ];
    }

    public function testNormalizeMetadataMapsMovieFields(): void
    {
        $out = TautulliClient::normalizeMetadata($this->metadataMovieFixture());

        self::assertSame('movie', $out['mediaType']);
        self::assertSame('See How They Run', $out['title']);
        self::assertSame('2022', $out['year']);
        self::assertSame('PG-13', $out['contentRating']);
        self::assertSame('Searchlight Pictures', $out['studio']);
        self::assertSame(['Comedy', 'Crime', 'Mystery'], $out['genres']);
        self::assertSame(['Tom George'], $out['directors']);
        self::assertSame(7.5, $out['ratings']['critic']);
        self::assertSame(8.1, $out['ratings']['audience']);
        self::assertSame('1080p', $out['media']['resolution']);
        self::assertSame('hevc', $out['media']['videoCodec']);
        self::assertSame('mkv', $out['media']['container']);
        self::assertSame(8700, $out['media']['bitrateKbps']);
        // 5_820_000 ms = 97 min
        self::assertSame('1 h 37 min', $out['durationLabel']);
    }

    public function testNormalizeMetadataCapsCastAtEight(): void
    {
        $out = TautulliClient::normalizeMetadata($this->metadataMovieFixture());
        self::assertCount(8, $out['cast']);
        self::assertSame('Sam Rockwell', $out['cast'][0]);
        self::assertNotContains('Extra Person Nine', $out['cast']);
    }

    public function testNormalizeMetadataMapsEpisodeFields(): void
    {
        $data = $this->metadataMovieFixture();
        $data['media_type']        = 'episode';
        $data['title']             = 'Ghost War';
        $data['grandparent_title'] = "Tom Clancy's Jack Ryan";
        $data['parent_media_index']= '1';
        $data['media_index']       = '3';
        $data['grandparent_thumb'] = '/library/metadata/100/thumb/2';

        $out = TautulliClient::normalizeMetadata($data);

        self::assertSame('episode', $out['mediaType']);
        self::assertSame("Tom Clancy's Jack Ryan", $out['grandparentTitle']);
        self::assertSame(1, $out['season']);
        self::assertSame(3, $out['episode']);
    }

    public function testNormalizeMetadataStripsPrivateFields(): void
    {
        $out = TautulliClient::normalizeMetadata($this->metadataMovieFixture());
        foreach (['file', 'section_id', 'guid', 'media_info'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $out, "private field {$forbidden} leaked");
        }
        $flat = json_encode($out);
        self::assertStringNotContainsString('/data/media/movies', $flat);
        self::assertStringNotContainsString('plex://', $flat);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker exec -e APP_ENV=test prismarr vendor/bin/phpunit --filter normalizeMetadata`
Expected: FAIL — `Call to undefined method App\Service\Media\TautulliClient::normalizeMetadata()`.

- [ ] **Step 3: Add `getMetadata` + `normalizeMetadata` + a duration helper to `TautulliClient`**

In `symfony/src/Service/Media/TautulliClient.php`, add after the `getActivity()` method (before `fetchImage`):

```php
    /**
     * Full metadata for one Plex item, normalized + sanitized. Shaped like
     * getActivity(): always returns the flag/error envelope plus the data.
     *
     * @return array{
     *   enabled: bool, configured: bool, connected: bool, error: ?string,
     *   metadata: array<string, mixed>
     * }
     */
    public function getMetadata(string $ratingKey): array
    {
        $this->ensureConfig();
        $configured = $this->baseUrl !== '' && $this->apiKey !== '';

        $base = ['enabled' => $this->enabled, 'configured' => $configured, 'connected' => false, 'error' => null, 'metadata' => []];

        if (!$this->enabled)      { return $base; }
        if (!$configured)         { $base['error'] = 'unconfigured'; return $base; }
        if ($ratingKey === '' || !ctype_digit($ratingKey)) { $base['error'] = 'not_found'; return $base; }

        $resp = $this->request(['cmd' => 'get_metadata', 'rating_key' => $ratingKey]);
        if ($resp === null)        { $base['error'] = 'unreachable'; return $base; }
        if ($resp['ok'] !== true)  { $base['error'] = 'auth'; return $base; }
        if ($resp['data'] === [])  { $base['error'] = 'not_found'; return $base; }

        return ['enabled' => true, 'configured' => true, 'connected' => true, 'error' => null,
                'metadata' => self::normalizeMetadata($resp['data'])];
    }

    /**
     * Pure transform: get_metadata `data` → sanitized shape. Allow-list only:
     * file paths, section ids, guids, raw media_info and the raw payload are
     * dropped by construction.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function normalizeMetadata(array $data): array
    {
        $mediaType = self::str($data['media_type'] ?? null);
        $mi = (is_array($data['media_info'] ?? null) && isset($data['media_info'][0]) && is_array($data['media_info'][0]))
            ? $data['media_info'][0] : [];

        return [
            'mediaType'        => $mediaType,
            'title'            => self::str($data['title'] ?? null),
            'grandparentTitle' => self::str($data['grandparent_title'] ?? null),
            'year'             => self::str($data['year'] ?? null),
            'season'           => isset($data['parent_media_index']) ? (int) $data['parent_media_index'] : null,
            'episode'          => isset($data['media_index']) ? (int) $data['media_index'] : null,
            'summary'          => self::str($data['summary'] ?? null),
            'tagline'          => self::str($data['tagline'] ?? null),
            'contentRating'    => self::str($data['content_rating'] ?? null),
            'durationMs'       => (int) ($data['duration'] ?? 0),
            'durationLabel'    => self::durationLabel((int) ($data['duration'] ?? 0)),
            'genres'           => self::strList($data['genres'] ?? []),
            'ratings'          => [
                'critic'   => is_numeric($data['rating'] ?? null) ? (float) $data['rating'] : null,
                'audience' => is_numeric($data['audience_rating'] ?? null) ? (float) $data['audience_rating'] : null,
            ],
            'directors'        => self::strList($data['directors'] ?? []),
            'writers'          => self::strList($data['writers'] ?? []),
            'cast'             => array_slice(self::strList($data['actors'] ?? []), 0, 8),
            'studio'           => self::str($data['studio'] ?? null),
            'releaseDate'      => self::str($data['originally_available_at'] ?? null),
            'media'            => [
                'resolution'  => self::str($mi['video_full_resolution'] ?? ($mi['video_resolution'] ?? null)),
                'videoCodec'  => self::str($mi['video_codec'] ?? null),
                'audioCodec'  => self::str($mi['audio_codec'] ?? null),
                'container'   => self::str($mi['container'] ?? null),
                'bitrateKbps' => (int) ($mi['bitrate'] ?? 0),
            ],
        ];
    }

    /** ms → "1 h 37 min" / "45 min" / null when zero. */
    private static function durationLabel(int $ms): ?string
    {
        if ($ms <= 0) {
            return null;
        }
        $totalMin = (int) round($ms / 60000);
        $h = intdiv($totalMin, 60);
        $m = $totalMin % 60;
        return $h > 0 ? sprintf('%d h %02d min', $h, $m) : sprintf('%d min', $m);
    }

    /**
     * Coerce a Tautulli list (array of scalars) to a clean list<string>,
     * dropping empties. Non-arrays yield [].
     *
     * @return list<string>
     */
    private static function strList(mixed $v): array
    {
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $item) {
            $s = self::str($item);
            if ($s !== null) {
                $out[] = $s;
            }
        }
        return $out;
    }
```

- [ ] **Step 4: Generalize `request()` to accept query params**

`request()` currently hardcodes `get_activity`. Change its signature and the activity call to share it.

In `TautulliClient.php`, change the `request()` declaration and the `http_build_query` block from:

```php
    private function request(): ?array
    {
```
to:
```php
    /**
     * @param array<string, string> $params Tautulli command + args (apikey added here).
     */
    private function request(array $params = ['cmd' => 'get_activity']): ?array
    {
```

And change the URL build inside `request()` from:
```php
        $url = $endpoint . '?' . http_build_query([
            'apikey' => $this->apiKey,
            'cmd'    => 'get_activity',
        ]);
```
to:
```php
        $url = $endpoint . '?' . http_build_query(['apikey' => $this->apiKey] + $params);
```

(The existing `getActivity()`/`ping()` calls to `$this->request()` keep working via the default arg.)

- [ ] **Step 5: Keep `ratingKey` in the normalized session**

In `normalizeSession()`, add `ratingKey` to the returned array (right after `'sessionId'`):

```php
            'ratingKey'        => self::str($s['rating_key'] ?? null),
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `docker exec -e APP_ENV=test prismarr vendor/bin/phpunit --filter "normalizeMetadata|TautulliClient"`
Expected: PASS (all existing + new tests green).

- [ ] **Step 7: Commit**

```bash
git add symfony/src/Service/Media/TautulliClient.php symfony/tests/Service/Media/TautulliClientTest.php
git commit -m "feat(tautulli): add getMetadata + normalizeMetadata, keep ratingKey in sessions"
```

---

### Task 2: Metadata route in `TautulliController`

**Files:**
- Modify: `symfony/src/Controller/TautulliController.php`

- [ ] **Step 1: Add the route**

Add after `apiImage()` (before the closing `}`):

```php
    /**
     * GET /tautulli/api/metadata/{ratingKey} — renders the info-modal body for a
     * Plex item. ratingKey is digits-only (route requirement). Optional
     * player/device query params are display-only (live now-playing line) and
     * are Twig-escaped. Fails open: the template renders a clean error state.
     */
    #[Route('/api/metadata/{ratingKey}', name: 'api_metadata', methods: ['GET'], requirements: ['ratingKey' => '\d+'])]
    public function apiMetadata(string $ratingKey, Request $request): Response
    {
        try {
            $data = $this->tautulli->getMetadata($ratingKey);
        } catch (\Throwable) {
            $data = ['enabled' => true, 'configured' => true, 'connected' => false, 'error' => 'unreachable', 'metadata' => []];
        }

        return $this->render('dashboard/_plex_metadata.html.twig', [
            'plex'   => $data,
            'player' => trim((string) $request->query->get('player', '')),
            'device' => trim((string) $request->query->get('device', '')),
        ]);
    }
```

- [ ] **Step 2: Verify the route is registered**

Run: `docker exec prismarr php bin/console debug:router app_tautulli_api_metadata`
Expected: shows `GET /tautulli/api/metadata/{ratingKey}`.

(The template `dashboard/_plex_metadata.html.twig` is created in Task 3; this route renders it.)

- [ ] **Step 3: Commit**

```bash
git add symfony/src/Controller/TautulliController.php
git commit -m "feat(tautulli): add /tautulli/api/metadata/{ratingKey} modal endpoint"
```

---

### Task 3: Modal body template `_plex_metadata.html.twig`

**Files:**
- Create: `symfony/templates/dashboard/_plex_metadata.html.twig`

- [ ] **Step 1: Create the template**

```twig
{# Modal body for the Plex info modal. `plex` is the getMetadata() envelope.
   player/device are display-only live-session strings (empty when opened from
   a recently-watched row). Server-rendered + sanitized — no secrets. #}
{% set m = plex.metadata %}
{% if plex.error or m is empty %}
  <div class="modal-body text-center text-secondary py-5">{{ 'dashboard.plex.modal.error'|trans }}</div>
{% else %}
  <div class="modal-header">
    <h3 class="modal-title">
      {% if m.mediaType == 'episode' and m.grandparentTitle %}{{ m.grandparentTitle }}{% else %}{{ m.title }}{% endif %}
      {% if m.year %}<span class="text-secondary fw-normal">({{ m.year }})</span>{% endif %}
    </h3>
    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
  </div>
  <div class="modal-body">
    {% if m.mediaType == 'episode' and (m.season or m.episode) %}
      <div class="text-secondary mb-2">{{ 'S%02dE%02d'|format(m.season ?? 0, m.episode ?? 0) }}{% if m.title %} · {{ m.title }}{% endif %}</div>
    {% endif %}

    <div class="d-flex flex-wrap gap-2 mb-3">
      {% if m.contentRating %}<span class="badge bg-secondary-lt">{{ m.contentRating }}</span>{% endif %}
      {% if m.durationLabel %}<span class="badge bg-secondary-lt">{{ m.durationLabel }}</span>{% endif %}
      {% if m.ratings.audience %}<span class="badge bg-yellow-lt text-yellow">★ {{ m.ratings.audience }}</span>{% endif %}
      {% if m.ratings.critic %}<span class="badge bg-azure-lt text-azure">{{ 'dashboard.plex.modal.critic'|trans }} {{ m.ratings.critic }}</span>{% endif %}
      {% for g in m.genres %}<span class="badge bg-secondary-lt">{{ g }}</span>{% endfor %}
    </div>

    {% if m.tagline %}<p class="fst-italic text-secondary">{{ m.tagline }}</p>{% endif %}
    {% if m.summary %}<p>{{ m.summary }}</p>{% endif %}

    {% if m.directors is not empty %}<p class="mb-1"><strong>{{ 'dashboard.plex.modal.directors'|trans }}</strong> {{ m.directors|join(', ') }}</p>{% endif %}
    {% if m.writers is not empty %}<p class="mb-1"><strong>{{ 'dashboard.plex.modal.writers'|trans }}</strong> {{ m.writers|join(', ') }}</p>{% endif %}
    {% if m.cast is not empty %}<p class="mb-1"><strong>{{ 'dashboard.plex.modal.cast'|trans }}</strong> {{ m.cast|join(', ') }}</p>{% endif %}
    {% if m.studio %}<p class="mb-1"><strong>{{ 'dashboard.plex.modal.studio'|trans }}</strong> {{ m.studio }}</p>{% endif %}

    {% set tech = [] %}
    {% if m.media.resolution %}{% set tech = tech|merge([m.media.resolution]) %}{% endif %}
    {% if m.media.videoCodec %}{% set tech = tech|merge([m.media.videoCodec|upper]) %}{% endif %}
    {% if m.media.audioCodec %}{% set tech = tech|merge([m.media.audioCodec|upper]) %}{% endif %}
    {% if m.media.container %}{% set tech = tech|merge([m.media.container|upper]) %}{% endif %}
    {% if m.media.bitrateKbps > 0 %}{% set tech = tech|merge([(m.media.bitrateKbps / 1000)|round(1) ~ ' ' ~ ('dashboard.plex.mbps'|trans)]) %}{% endif %}
    {% if tech is not empty or player or device %}
      <hr>
      <div class="text-secondary small">
        <div class="fw-bold text-uppercase mb-1">{{ 'dashboard.plex.modal.tech'|trans }}</div>
        {% if tech is not empty %}<div>{{ tech|join(' · ') }}</div>{% endif %}
        {% if player or device %}<div>{{ [player, device]|filter(v => v)|join(' · ') }}</div>{% endif %}
      </div>
    {% endif %}
  </div>
{% endif %}
```

- [ ] **Step 2: Lint the template**

Run: `docker exec prismarr php bin/console lint:twig templates/dashboard/_plex_metadata.html.twig`
Expected: `[OK] ... valid syntax.`

- [ ] **Step 3: Commit**

```bash
git add symfony/templates/dashboard/_plex_metadata.html.twig
git commit -m "feat(tautulli): add Plex info modal body template"
```

---

### Task 4: Modal shell + open JS; make now-playing sessions clickable

**Files:**
- Modify: `symfony/templates/dashboard/_plex_activity.html.twig`
- Modify: `symfony/templates/dashboard/index.html.twig`

- [ ] **Step 1: Make the session poster + title a modal trigger**

In `_plex_activity.html.twig`, replace the poster `<div class="plex-poster"> … </div>` and the title block so both carry trigger data. Change the poster wrapper opening tag and the title div to buttons/anchors with data attributes.

Replace:
```twig
        <div class="plex-poster">
          {{ ico.icon(s.mediaType == 'movie' ? 'movie' : 'device-tv', '', 22) }}
          {% if s.posterPath %}
```
with:
```twig
        <div class="plex-poster {% if s.ratingKey %}plex-clickable{% endif %}"
             {% if s.ratingKey %}data-plex-rating-key="{{ s.ratingKey }}" data-plex-player="{{ s.player }}" data-plex-device="{{ s.device }}" role="button" tabindex="0"{% endif %}>
          {{ ico.icon(s.mediaType == 'movie' ? 'movie' : 'device-tv', '', 22) }}
          {% if s.posterPath %}
```

And replace the title line:
```twig
          <div class="plex-session-title text-truncate">
```
with:
```twig
          <div class="plex-session-title text-truncate {% if s.ratingKey %}plex-clickable{% endif %}"
               {% if s.ratingKey %}data-plex-rating-key="{{ s.ratingKey }}" data-plex-player="{{ s.player }}" data-plex-device="{{ s.device }}" role="button" tabindex="0"{% endif %}>
```

- [ ] **Step 2: Add the modal shell + CSS + open handler in `index.html.twig`**

Add the modal shell once, just before the closing `{% endblock %}` of the body content (or near the other dashboard markup). Place it right after the Plex widget card block:

```twig
{# Shared Plex info modal — body injected by JS from /tautulli/api/metadata #}
<div class="modal modal-blur fade" id="plex-info-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content" data-plex-modal-body>
      <div class="modal-body text-center py-5"><span class="spinner-border"></span></div>
    </div>
  </div>
</div>
```

Add CSS to the existing `<style>` block (near the other `.plex-*` rules):
```css
  .plex-clickable { cursor: pointer; }
  .plex-session-title.plex-clickable:hover { color: var(--tblr-primary); }
```

Add the open handler inside the existing dashboard IIFE in `index.html.twig`, right after the `refreshPlex` definition (before the `turbo:before-render` listener):
```javascript
    // Plex info modal: delegated click on any [data-plex-rating-key] trigger.
    function openPlexModal(ratingKey, player, device) {
      var modalEl = document.getElementById('plex-info-modal');
      if (!modalEl || !window.bootstrap) return;
      var body = modalEl.querySelector('[data-plex-modal-body]');
      body.innerHTML = '<div class="modal-body text-center py-5"><span class="spinner-border"></span></div>';
      var modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
      modal.show();
      // Build the URL directly — we can't use path() with a placeholder because
      // the route's \d+ requirement rejects a non-numeric stand-in at render
      // time. ratingKey is always digits (validated again server-side).
      var url = '/tautulli/api/metadata/' + encodeURIComponent(ratingKey)
        + '?player=' + encodeURIComponent(player || '') + '&device=' + encodeURIComponent(device || '');
      fetch(url, { headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.text() : ''; })
        .then(function (html) { body.innerHTML = html || '<div class="modal-body text-center text-secondary py-5">—</div>'; })
        .catch(function () { body.innerHTML = '<div class="modal-body text-center text-secondary py-5">—</div>'; });
    }
    if (!window._plexModalDelegated) {
      window._plexModalDelegated = true;
      document.addEventListener('click', function (e) {
        var t = e.target.closest('[data-plex-rating-key]');
        if (!t) return;
        openPlexModal(t.getAttribute('data-plex-rating-key'), t.getAttribute('data-plex-player'), t.getAttribute('data-plex-device'));
      });
    }
```

- [ ] **Step 3: Lint templates**

Run: `docker exec prismarr php bin/console lint:twig templates/dashboard`
Expected: all valid.

- [ ] **Step 4: Manual verification (after image rebuild on Unraid, or local container)**

Open the dashboard with an active stream → click a poster/title → modal opens with metadata. Confirm no API key appears in the network request to `/tautulli/api/metadata/...`.

- [ ] **Step 5: Commit**

```bash
git add symfony/templates/dashboard/_plex_activity.html.twig symfony/templates/dashboard/index.html.twig
git commit -m "feat(tautulli): clickable sessions open the Plex info modal"
```

---

### Task 5: `getHistory` + `normalizeHistory` + cache in `DashboardController`

**Files:**
- Modify: `symfony/src/Service/Media/TautulliClient.php`
- Modify: `symfony/src/Controller/DashboardController.php`
- Test: `symfony/tests/Service/Media/TautulliClientTest.php`

- [ ] **Step 1: Write the failing tests**

Add to `TautulliClientTest.php`:

```php
    /** A representative get_history `data` envelope (Tautulli wraps rows in `data`). */
    private function historyFixture(): array
    {
        return [
            'recordsFiltered' => 2,
            'data' => [
                [
                    'rating_key'        => '12345',
                    'media_type'        => 'movie',
                    'full_title'        => 'See How They Run',
                    'title'             => 'See How They Run',
                    'year'              => '2022',
                    'thumb'             => '/library/metadata/12345/thumb/1',
                    'friendly_name'     => 'nDanDan',
                    'user'              => 'nDanDan',
                    'date'              => 1781377600,
                    'percent_complete'  => 96,
                    'watched_status'    => 1,
                    // private:
                    'ip_address'        => '192.168.1.5',
                    'user_id'           => 99,
                ],
                [
                    'rating_key'        => '777',
                    'media_type'        => 'episode',
                    'full_title'        => "Tom Clancy's Jack Ryan - Ghost War",
                    'title'             => 'Ghost War',
                    'grandparent_title' => "Tom Clancy's Jack Ryan",
                    'grandparent_thumb' => '/library/metadata/100/thumb/2',
                    'thumb'             => '/library/metadata/777/thumb/3',
                    'friendly_name'     => 'nDanDan',
                    'username'          => 'plexlogin_secret',
                    'date'              => 1781370000,
                    'percent_complete'  => 50,
                ],
            ],
        ];
    }

    public function testNormalizeHistoryMapsRows(): void
    {
        $out = TautulliClient::normalizeHistory($this->historyFixture());
        self::assertCount(2, $out);

        self::assertSame('12345', $out[0]['ratingKey']);
        self::assertSame('See How They Run', $out[0]['title']);
        self::assertSame('nDanDan', $out[0]['userDisplayName']);
        self::assertSame(1781377600, $out[0]['watchedAt']);
        self::assertSame(96, $out[0]['percentComplete']);
        self::assertSame('/library/metadata/12345/thumb/1', $out[0]['posterPath']);
    }

    public function testNormalizeHistoryEpisodePrefersGrandparentPoster(): void
    {
        $out = TautulliClient::normalizeHistory($this->historyFixture());
        self::assertSame('episode', $out[1]['mediaType']);
        self::assertSame("Tom Clancy's Jack Ryan", $out[1]['grandparentTitle']);
        self::assertSame('/library/metadata/100/thumb/2', $out[1]['posterPath']);
    }

    public function testNormalizeHistoryNeverLeaksPlexLogin(): void
    {
        $out = TautulliClient::normalizeHistory($this->historyFixture());
        $flat = json_encode($out);
        self::assertStringNotContainsString('plexlogin_secret', $flat);
        self::assertStringNotContainsString('192.168.1.5', $flat);
        foreach ($out as $row) {
            self::assertArrayNotHasKey('username', $row);
            self::assertArrayNotHasKey('ip_address', $row);
        }
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker exec -e APP_ENV=test prismarr vendor/bin/phpunit --filter normalizeHistory`
Expected: FAIL — undefined method `normalizeHistory`.

- [ ] **Step 3: Add `getHistory` + `normalizeHistory` to `TautulliClient`**

Add after `getMetadata()`:

```php
    /**
     * Recent watch history, normalized + sanitized. Returns a plain list (no
     * envelope) — an empty list covers disabled/unconfigured/unreachable so the
     * widget's "recently watched" pane just shows its empty state.
     *
     * @return list<array<string, mixed>>
     */
    public function getHistory(int $length = 8): array
    {
        $this->ensureConfig();
        if (!$this->enabled || $this->baseUrl === '' || $this->apiKey === '') {
            return [];
        }
        $resp = $this->request([
            'cmd'          => 'get_history',
            'length'       => (string) max(1, min(50, $length)),
            'order_column' => 'date',
            'order_dir'    => 'desc',
        ]);
        if ($resp === null || $resp['ok'] !== true) {
            return [];
        }
        return self::normalizeHistory($resp['data']);
    }

    /**
     * Pure transform: get_history `data` envelope → sanitized rows. Allow-list
     * only; usernames/emails/IPs/file paths are never copied out.
     *
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    public static function normalizeHistory(array $data): array
    {
        $rows = is_array($data['data'] ?? null) ? $data['data'] : [];
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $mediaType = self::str($r['media_type'] ?? null);
            $out[] = [
                'ratingKey'        => self::str($r['rating_key'] ?? null),
                'mediaType'        => $mediaType,
                'title'            => self::str($r['title'] ?? ($r['full_title'] ?? null)),
                'grandparentTitle' => self::str($r['grandparent_title'] ?? null),
                'year'             => self::str($r['year'] ?? null),
                'posterPath'       => self::pickPoster($r, $mediaType),
                // Display name only — never username (Plex login) or email.
                'userDisplayName'  => self::str($r['friendly_name'] ?? ($r['user'] ?? null)),
                'watchedAt'        => (int) ($r['date'] ?? 0),
                'percentComplete'  => (int) ($r['percent_complete'] ?? 0),
            ];
        }
        return $out;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker exec -e APP_ENV=test prismarr vendor/bin/phpunit --filter "normalizeHistory|TautulliClient"`
Expected: PASS.

- [ ] **Step 5: Wire history + default tab into `DashboardController::widgetPlex`**

In `symfony/src/Controller/DashboardController.php`, replace the body of `widgetPlex()`:

```php
    public function widgetPlex(): Response
    {
        if (!$this->health->isConfigured('tautulli')) {
            return new Response('');
        }
        set_time_limit(60);

        $activity = $this->tautulli->getActivity();

        // History changes slowly; cache it ~60s so the 10s now-playing poll
        // doesn't hit get_history every time. Map each row's epoch to the same
        // friendly relative label used by the recent-additions widget.
        $now = new \DateTimeImmutable();
        $history = array_map(function (array $row) use ($now) {
            $row['when'] = $row['watchedAt'] > 0
                ? $this->relativeDate((new \DateTimeImmutable())->setTimestamp($row['watchedAt']), $now)
                : null;
            return $row;
        }, $this->cached('plex_history', fn() => $this->tautulli->getHistory(8)));

        $streaming = ($activity['streamCount'] ?? 0) > 0;

        return $this->render('dashboard/_plex_activity.html.twig', [
            'plex'        => $activity,
            'plex_history'=> $history,
            'plex_tab'    => $streaming ? 'now' : 'recent',
        ]);
    }
```

Note: `cached()` and `relativeDate()` already exist on `DashboardController` (used by other widgets). No new helpers.

- [ ] **Step 6: Commit**

```bash
git add symfony/src/Service/Media/TautulliClient.php symfony/src/Controller/DashboardController.php symfony/tests/Service/Media/TautulliClientTest.php
git commit -m "feat(tautulli): add getHistory + normalizeHistory, wire into dashboard widget"
```

---

### Task 6: Tabs + recently-watched pane + tab-preserving refresh

**Files:**
- Modify: `symfony/templates/dashboard/_plex_activity.html.twig`
- Modify: `symfony/templates/dashboard/index.html.twig`

- [ ] **Step 1: Wrap the widget body in tabs**

In `_plex_activity.html.twig`, wrap the existing now-playing markup and add the recently-watched pane. At the very top of the file (after the `{% import %}`), add the tab header; wrap the existing content in the "now" pane; append the "recent" pane. Structure:

```twig
{% import '_icons.html.twig' as ico %}
{% set active_tab = plex_tab|default('now') %}
<ul class="nav nav-tabs plex-tabs" data-plex-tabs>
  <li class="nav-item"><a href="#" class="nav-link {{ active_tab == 'now' ? 'active' }}" data-plex-tab="now">{{ 'dashboard.plex.tab.now'|trans }}{% if plex.streamCount > 0 %} <span class="badge bg-green-lt text-green ms-1">{{ plex.streamCount }}</span>{% endif %}</a></li>
  <li class="nav-item"><a href="#" class="nav-link {{ active_tab == 'recent' ? 'active' }}" data-plex-tab="recent">{{ 'dashboard.plex.tab.recent'|trans }}</a></li>
</ul>

<div class="plex-pane" data-plex-pane="now" {% if active_tab != 'now' %}style="display:none"{% endif %}>
  {# ↓↓↓ EXISTING now-playing markup (the {% if plex.error … %} … {% endif %} block) goes here unchanged ↓↓↓ #}
</div>

<div class="plex-pane" data-plex-pane="recent" {% if active_tab != 'recent' %}style="display:none"{% endif %}>
  {% if plex_history is empty %}
    <div class="text-center text-secondary py-4">{{ 'dashboard.plex.recent.empty'|trans }}</div>
  {% else %}
    <div class="plex-sessions">
      {% for h in plex_history %}
        <div class="plex-session {% if h.ratingKey %}plex-clickable{% endif %}"
             {% if h.ratingKey %}data-plex-rating-key="{{ h.ratingKey }}" role="button" tabindex="0"{% endif %}>
          <div class="plex-poster">
            {{ ico.icon(h.mediaType == 'movie' ? 'movie' : 'device-tv', '', 22) }}
            {% if h.posterPath %}<img class="plex-poster-img" src="{{ path('app_tautulli_api_image', {img: h.posterPath}) }}" alt="" loading="lazy">{% endif %}
          </div>
          <div class="plex-session-main">
            <div class="plex-session-title text-truncate">
              {% if h.mediaType == 'episode' and h.grandparentTitle %}{{ h.grandparentTitle }}{% else %}{{ h.title }}{% endif %}
              {% if h.year %}<span class="plex-session-year">({{ h.year }})</span>{% endif %}
            </div>
            <div class="plex-session-meta text-truncate">
              {{ [h.userDisplayName, h.when]|filter(v => v is not null)|join(' · ') }}
            </div>
            {% if h.percentComplete > 0 %}
              <div class="progress plex-progress"><div class="progress-bar" style="width: {{ h.percentComplete }}%"></div></div>
            {% endif %}
          </div>
        </div>
      {% endfor %}
    </div>
  {% endif %}
</div>
```

Take the entire existing `{% if plex.error == 'unconfigured' %} … {% endif %}` block (the whole now-playing render) and move it inside the `data-plex-pane="now"` div where indicated.

- [ ] **Step 2: Add tab-toggle JS + preserve active tab across refresh**

In `index.html.twig`, add a delegated tab handler (next to the modal handler):
```javascript
    // Plex widget tabs: client-side pane toggle, remembers the active tab so the
    // 10s fragment refresh doesn't snap the user back to "Now playing".
    if (!window._plexTabsDelegated) {
      window._plexTabsDelegated = true;
      document.addEventListener('click', function (e) {
        var tab = e.target.closest('[data-plex-tab]');
        if (!tab) return;
        e.preventDefault();
        window._plexActiveTab = tab.getAttribute('data-plex-tab');
        applyPlexTab(tab.closest('[data-plex-tabs]').parentNode);
      });
    }
    function applyPlexTab(scope) {
      if (!scope) return;
      var want = window._plexActiveTab;
      if (!want) return;
      scope.querySelectorAll('[data-plex-tab]').forEach(function (a) {
        a.classList.toggle('active', a.getAttribute('data-plex-tab') === want);
      });
      scope.querySelectorAll('[data-plex-pane]').forEach(function (p) {
        p.style.display = (p.getAttribute('data-plex-pane') === want) ? '' : 'none';
      });
    }
```

Then, in the existing `refreshPlex()` function, after `applyFragment(node, html)` runs, re-apply the remembered tab. Change the `.then` in `refreshPlex` from:
```javascript
        .then(function (html) { if ((html || '').trim()) applyFragment(node, html); })
```
to:
```javascript
        .then(function (html) { if ((html || '').trim()) { applyFragment(node, html); applyPlexTab(node.querySelector('[data-dash-body]') || node); } })
```

- [ ] **Step 3: Lint templates**

Run: `docker exec prismarr php bin/console lint:twig templates/dashboard`
Expected: all valid.

- [ ] **Step 4: Commit**

```bash
git add symfony/templates/dashboard/_plex_activity.html.twig symfony/templates/dashboard/index.html.twig
git commit -m "feat(tautulli): add Now playing / Recently watched tabs to the widget"
```

---

### Task 7: Sidebar streaming pill

**Files:**
- Modify: `symfony/templates/base.html.twig`
- Modify: `symfony/templates/dashboard/index.html.twig` (anchor id)

- [ ] **Step 1: Add the anchor id to the widget card**

In `index.html.twig`, on the Plex widget's outer column div, add `id="plex-activity"`. Change:
```twig
      <div class="col-12" data-dash-widget="plex" data-dash-url="{{ path('app_dashboard_widget_plex') }}">
```
to:
```twig
      <div class="col-12" id="plex-activity" data-dash-widget="plex" data-dash-url="{{ path('app_dashboard_widget_plex') }}">
```

- [ ] **Step 2: Add the pill markup under the logo**

In `base.html.twig`, immediately after the `.navbar-brand` closing `</div>` (after line ~693), add the pill markup **unconditionally** (it's tiny and hidden by default). Tautulli has no sidebar nav entry, so `service_visible_in_sidebar('tautulli')` would hide it permanently — instead the poller (Step 4) governs visibility and stops itself when Tautulli isn't configured (the `/tautulli/api/activity` response carries `configured`/`enabled`):
```twig
        {# Live Plex streaming pill — hidden until the poller finds active streams. #}
        <a href="{{ path('app_dashboard') }}#plex-activity" id="sidebar-plex-pill" class="sidebar-plex-pill" style="display:none;">
          <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="currentColor"><path d="M7 4v16l13 -8z"/></svg>
          <span data-plex-pill-count>0</span>
        </a>
```

- [ ] **Step 3: Add the pill CSS**

In the `<style>` block of `base.html.twig`, near `.sidebar-dl-badge`:
```css
    .sidebar-plex-pill {
      display: inline-flex; align-items: center; gap: 5px;
      margin: 6px 0 2px; padding: 2px 9px;
      font-size: .75rem; font-weight: 600;
      color: var(--tblr-green); background: var(--tblr-green-lt);
      border-radius: 999px; text-decoration: none;
    }
    html.sidebar-collapsed aside.navbar-vertical .sidebar-plex-pill span { display: none; }
```

- [ ] **Step 4: Add the pill hydration JS**

Near the other sidebar poller IIFEs (e.g. after the Seerr poller block), add:
```twig
  <script>
  (function () {
    var pill = document.getElementById('sidebar-plex-pill');
    if (!pill) return;
    var URL = '{{ path('app_tautulli_api_activity') }}';
    var INTERVAL = 30000;
    function tick() {
      if (document.hidden) return;
      fetch(URL, { headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          // Tautulli not set up → stop polling entirely (don't poke it every 30s).
          if (d && (d.configured === false || d.enabled === false)) {
            if (window._plexPillTimer) { clearInterval(window._plexPillTimer); window._plexPillTimer = null; }
            pill.style.display = 'none';
            return;
          }
          var n = d && d.streamCount ? d.streamCount : 0;
          if (n > 0) { pill.querySelector('[data-plex-pill-count]').textContent = n > 99 ? '99+' : n; pill.style.display = ''; }
          else { pill.style.display = 'none'; }
        })
        .catch(function () { pill.style.display = 'none'; });
    }
    tick();
    if (window._plexPillTimer) { clearInterval(window._plexPillTimer); }
    window._plexPillTimer = setInterval(tick, INTERVAL);
  })();
  </script>
```

- [ ] **Step 5: Lint templates**

Run: `docker exec prismarr php bin/console lint:twig templates`
Expected: all valid.

- [ ] **Step 6: Commit**

```bash
git add symfony/templates/base.html.twig symfony/templates/dashboard/index.html.twig
git commit -m "feat(tautulli): add live streaming pill under the sidebar logo"
```

---

### Task 8: Translations (en + fr)

**Files:**
- Modify: `symfony/translations/messages+intl-icu.en.yaml`
- Modify: `symfony/translations/messages+intl-icu.fr.yaml`

- [ ] **Step 1: Add the new keys under the existing `plex:` block (en)**

Under the existing `dashboard: > plex:` mapping in `messages+intl-icu.en.yaml`, add:
```yaml
    tab:
      now: Now playing
      recent: Recently watched
    recent:
      empty: No recent Plex history
    modal:
      error: Couldn't load this title
      critic: Critics
      directors: 'Director:'
      writers: 'Writer:'
      cast: 'Cast:'
      studio: 'Studio:'
      tech: Stream
```

- [ ] **Step 2: Add the same keys (fr)**

Under `dashboard: > plex:` in `messages+intl-icu.fr.yaml`:
```yaml
    tab:
      now: En cours
      recent: Vus récemment
    recent:
      empty: Aucun historique Plex récent
    modal:
      error: Impossible de charger ce titre
      critic: Critiques
      directors: 'Réalisation :'
      writers: 'Scénario :'
      cast: 'Distribution :'
      studio: 'Studio :'
      tech: Flux
```

- [ ] **Step 3: Lint translations + Twig**

Run: `docker exec prismarr php bin/console lint:yaml translations`
Expected: all valid.

- [ ] **Step 4: Commit**

```bash
git add symfony/translations/messages+intl-icu.en.yaml symfony/translations/messages+intl-icu.fr.yaml
git commit -m "i18n(tautulli): strings for tabs, info modal and recently-watched"
```

---

### Task 9: CHANGELOG + full check + push

**Files:**
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Extend the unreleased Tautulli entry**

Append to the existing unreleased Tautulli bullet in `CHANGELOG.md`:
```
Clicking a stream (or a recently-watched item) opens an in-app info modal (synopsis, ratings, cast/crew, stream detail) pulled server-side from `get_metadata`; the widget gains a "Recently watched" tab (`get_history`, defaulting to it when nothing is streaming); and a live pill under the sidebar logo shows the active stream count at a glance and links to the dashboard.
```

- [ ] **Step 2: Run the full check suite in-container**

Run: `make check`
Expected: PHP lint OK, all Twig valid, PHPUnit green.

- [ ] **Step 3: Commit + push**

```bash
git add CHANGELOG.md
git commit -m "docs(changelog): note Plex info modal, recently-watched tab and sidebar pill"
git push origin main
```

- [ ] **Step 4: Verify CI + image**

After push: confirm CI (`make check`) is green and the GHCR `:latest` image rebuilt, then Force Update on Unraid and verify the modal, tab, and pill end-to-end.

---

## Self-Review notes

- **Spec coverage:** Feature 1 (modal) = Tasks 1–4; Feature 2 (pill) = Task 7; Feature 3 (history tab) = Tasks 5–6; i18n = Task 8; changelog/CI = Task 9. Default-tab-when-idle = Task 5 Step 5 (`plex_tab`). Modal-from-history (no player/device) = Task 6 Step 1 (history triggers omit player/device → modal omits the line). All spec sections mapped.
- **Type consistency:** session/​history both use `ratingKey`, `posterPath`, `mediaType`, `grandparentTitle`, `userDisplayName`; metadata uses `media.{resolution,videoCodec,audioCodec,container,bitrateKbps}`, `ratings.{critic,audience}`, `durationLabel`. `request(array $params)` shared by activity/metadata/history. Route name `app_tautulli_api_metadata`. Trigger attribute `data-plex-rating-key` consistent across now-playing and history rows and the JS handler.
- **Helpers reused:** `self::str`, `self::pickPoster`, `DashboardController::cached`, `DashboardController::relativeDate` — all pre-existing.
```
