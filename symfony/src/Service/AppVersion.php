<?php

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Version + release-notes provider for the /admin/settings → Updates page.
 *
 * The page tracks the *fork's* `main` branch: the build stamps the exact git
 * SHA it was built from (the `PRISMARR_GIT_SHA` env), and this service compares
 * that SHA against fork `main` via GitHub's compare API to report how many
 * commits behind the running build is, plus a bounded feed of recent fork
 * commits and the fork's CHANGELOG. Upstream (Shoshuo/Prismarr) releases are
 * fetched too, but purely informational — they no longer drive
 * {@see isUpdateAvailable()}.
 *
 * The running version is whatever the build stamped into the `PRISMARR_VERSION`
 * env var (the release workflow passes the git tag, the beta workflow passes
 * `1.1.0-beta.N`). When that env is empty or the placeholder `dev` — i.e. a
 * local `make dev` build — it falls back to the {@see VERSION} constant. So the
 * constant only matters for local development; published images always report
 * the channel they came from.
 *
 * All GitHub calls are public/no-auth and cached for an hour. If the network
 * is unavailable, the page falls back to displaying just the current version.
 */
class AppVersion implements ResetInterface
{
    /** Local-dev fallback when PRISMARR_VERSION is unset or `dev`. */
    public const VERSION = '1.1.2-dev';

    private const UPSTREAM_RELEASES_URL = 'https://api.github.com/repos/Shoshuo/Prismarr/releases?per_page=15';
    // v2: schema bump (added `body_html` rendered from Markdown). Old v1 cache
    // entries are simply ignored; they expire on their own after 1 h.
    private const CACHE_KEY      = 'app_version.releases.v2';
    private const CACHE_TTL      = 3600; // 1 hour

    private const FORK_REPO          = 'ndandan/Prismarr';
    private const FORK_COMPARE_URL   = 'https://api.github.com/repos/' . self::FORK_REPO . '/compare/%s...main';
    private const FORK_CHANGELOG_URL = 'https://raw.githubusercontent.com/' . self::FORK_REPO . '/main/CHANGELOG.md';

    private const COMPARE_CACHE_KEY   = 'app_version.fork_compare.v1';
    private const CHANGELOG_CACHE_KEY = 'app_version.fork_changelog.v1';

    /** Resolved once in the constructor: PRISMARR_VERSION, or the constant. */
    private readonly string $version;

    /** @var array<int, array{tag:string,name:string,body:string,body_html:string,published_at:string,html_url:string}>|null */
    private ?array $releasesInProcess = null;

    /** @var array{behind: int|null, commits: array<int, array{sha7: string, subject: string, date: string, html_url: string}>}|null */
    private ?array $compareInProcess = null;

    /** Rendered changelog HTML; '' = fetch failed this request (not cached). */
    private ?string $changelogInProcess = null;

    private readonly string $gitSha;

    public function __construct(
        private readonly CacheItemPoolInterface $cacheApp,
        private readonly LoggerInterface        $logger,
        #[Autowire(env: 'default::PRISMARR_VERSION')]
        string $runtimeVersion = '',
        #[Autowire(env: 'default::PRISMARR_GIT_SHA')]
        ?string $gitSha = null,
    ) {
        $this->version = ($runtimeVersion !== '' && $runtimeVersion !== 'dev')
            ? $runtimeVersion
            : self::VERSION;
        // Symfony's `default:` env processor resolves to `null` (not the PHP
        // default) whenever PRISMARR_GIT_SHA is unset or empty — which is the
        // normal case for local `make dev` builds (Dockerfile stamps it via an
        // empty-by-default ARG). Normalise to '' so builtSha()'s `!== ''` check
        // and the readonly string property stay simple.
        $this->gitSha = $gitSha ?? '';
    }

    public function reset(): void
    {
        $this->releasesInProcess  = null;
        $this->compareInProcess   = null;
        $this->changelogInProcess = null;
    }

    public function current(): string
    {
        return $this->version;
    }

    /**
     * Latest GitHub release tag (without the leading `v`), or null if the
     * API is unreachable or returned nothing usable.
     */
    public function latest(): ?string
    {
        $first = $this->releases()[0] ?? null;
        return $first['tag'] ?? null;
    }

    public function builtSha(): ?string
    {
        return $this->gitSha !== '' ? $this->gitSha : null;
    }

    public function builtShaShort(): ?string
    {
        $sha = $this->builtSha();
        return $sha === null ? null : substr($sha, 0, 7);
    }

    /** Commits fork main has that this build does not; null when unknowable. */
    public function commitsBehind(): ?int
    {
        return $this->compare()['behind'];
    }

    /** @return array<int, array{sha7: string, subject: string, date: string, html_url: string}> */
    public function recentForkCommits(): array
    {
        return $this->compare()['commits'];
    }

    /** @return bool true when the running build is strictly behind fork main. */
    public function isUpdateAvailable(): bool
    {
        return ($this->commitsBehind() ?? 0) > 0;
    }

    /**
     * Latest upstream release, for the informational block.
     *
     * @return array{tag: string, published_at: string, html_url: string}|null
     */
    public function upstream(): ?array
    {
        $first = $this->releases()[0] ?? null;
        if ($first === null) {
            return null;
        }
        return [
            'tag'          => $first['tag'],
            'published_at' => $first['published_at'],
            'html_url'     => $first['html_url'],
        ];
    }

    /** Rendered fork CHANGELOG (Unreleased + two most recent released sections). */
    public function changelogHtml(): ?string
    {
        if ($this->changelogInProcess !== null) {
            return $this->changelogInProcess === '' ? null : $this->changelogInProcess;
        }

        $item = $this->cacheApp->getItem(self::CHANGELOG_CACHE_KEY);
        if ($item->isHit() && is_string($item->get()) && $item->get() !== '') {
            return $this->changelogInProcess = $item->get();
        }

        $md = $this->httpGet(self::FORK_CHANGELOG_URL, 'text/plain');
        if ($md === null) {
            $this->changelogInProcess = '';
            return null;
        }

        $html = self::renderBody(self::sliceChangelog($md));
        $item->set($html);
        $item->expiresAfter(self::CACHE_TTL);
        $this->cacheApp->save($item);

        return $this->changelogInProcess = $html;
    }

    /** @return array{behind: int|null, commits: array<int, array{sha7: string, subject: string, date: string, html_url: string}>} */
    private function compare(): array
    {
        if ($this->compareInProcess !== null) {
            return $this->compareInProcess;
        }

        $unavailable = ['behind' => null, 'commits' => []];

        $sha = $this->builtSha();
        if ($sha === null) {
            return $this->compareInProcess = $unavailable;
        }

        $item = $this->cacheApp->getItem(self::COMPARE_CACHE_KEY);
        if ($item->isHit()) {
            $cached = $item->get();
            if (is_array($cached) && array_key_exists('behind', $cached) && array_key_exists('commits', $cached)) {
                /** @var array{behind: int|null, commits: array<int, array{sha7: string, subject: string, date: string, html_url: string}>} $cached */
                return $this->compareInProcess = $cached;
            }
        }

        $body   = $this->httpGet(sprintf(self::FORK_COMPARE_URL, $sha), 'application/vnd.github+json');
        $parsed = $body === null ? null : self::parseComparePayload(json_decode($body, true));
        if ($parsed === null) {
            // Don't poison the cache with a failure (same pattern as releases()).
            return $this->compareInProcess = $unavailable;
        }

        $item->set($parsed);
        $item->expiresAfter(self::CACHE_TTL);
        $this->cacheApp->save($item);

        return $this->compareInProcess = $parsed;
    }

    /**
     * Parse a GitHub compare-API payload (base = the built SHA, head = main).
     * `ahead_by` = commits main has that the build does not. Commits arrive
     * oldest→newest; we keep the newest 15, newest first.
     *
     * Public + static so it can be unit-tested without booting the cache.
     *
     * @return array{behind: int, commits: array<int, array{sha7: string, subject: string, date: string, html_url: string}>}|null
     */
    public static function parseComparePayload(mixed $data): ?array
    {
        if (!is_array($data) || !isset($data['ahead_by']) || !is_int($data['ahead_by'])) {
            return null;
        }

        $commits = [];
        $raw     = is_array($data['commits'] ?? null) ? array_reverse($data['commits']) : [];
        foreach (array_slice($raw, 0, 15) as $c) {
            if (!is_array($c)) {
                continue;
            }
            $sha     = (string) ($c['sha'] ?? '');
            $message = (string) ($c['commit']['message'] ?? '');
            $subject = strtok($message, "\n");
            $commits[] = [
                'sha7'     => substr($sha, 0, 7),
                'subject'  => $subject === false ? '' : $subject,
                'date'     => (string) ($c['commit']['committer']['date'] ?? ''),
                'html_url' => (string) ($c['html_url'] ?? ''),
            ];
        }

        return ['behind' => $data['ahead_by'], 'commits' => $commits];
    }

    /**
     * Bound a Keep-a-Changelog file to its first three `## ` sections
     * (Unreleased + the two most recent releases), dropping the prelude.
     * Files with fewer sections keep what exists; section-less input is
     * returned unchanged.
     *
     * Public + static so it can be unit-tested without booting the cache.
     */
    public static function sliceChangelog(string $md): string
    {
        $md    = str_replace(["\r\n", "\r"], "\n", $md);
        $parts = preg_split('/^(?=## )/m', $md);
        if ($parts === false) {
            return $md;
        }

        $sections = array_values(array_filter($parts, static fn (string $p): bool => str_starts_with($p, '## ')));
        if ($sections === []) {
            return $md;
        }

        return implode('', array_slice($sections, 0, 3));
    }

    /**
     * @return array<int, array{tag:string,name:string,body:string,body_html:string,published_at:string,html_url:string}>
     */
    public function releases(): array
    {
        if ($this->releasesInProcess !== null) {
            return $this->releasesInProcess;
        }

        $item = $this->cacheApp->getItem(self::CACHE_KEY);
        if ($item->isHit()) {
            $cached = $item->get();
            if (is_array($cached)) {
                return $this->releasesInProcess = $cached;
            }
        }

        $fetched = $this->fetchFromGithub();
        if ($fetched === null) {
            // Don't poison the cache with a failure — let the next request
            // try again (network may be intermittent). Return empty list.
            return $this->releasesInProcess = [];
        }

        $item->set($fetched);
        $item->expiresAfter(self::CACHE_TTL);
        $this->cacheApp->save($item);

        return $this->releasesInProcess = $fetched;
    }

    /** GET a URL with the class's standard timeouts; null on any failure. */
    private function httpGet(string $url, string $accept): ?string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_NOSIGNAL       => 1,
            CURLOPT_HTTPHEADER     => [
                'Accept: ' . $accept,
                'User-Agent: Prismarr/' . $this->version,
            ],
        ]);

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $err !== '' || $code !== 200) {
            $this->logger->info('AppVersion fetch failed', ['url' => $url, 'code' => $code, 'error' => $err]);
            return null;
        }

        return (string) $body;
    }

    /**
     * @return array<int, array{tag:string,name:string,body:string,body_html:string,published_at:string,html_url:string}>|null
     */
    private function fetchFromGithub(): ?array
    {
        $body = $this->httpGet(self::UPSTREAM_RELEASES_URL, 'application/vnd.github+json');
        if ($body === null) {
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return null;
        }

        $releases = [];
        foreach ($data as $r) {
            if (!is_array($r)) {
                continue;
            }
            $tag = (string) ($r['tag_name'] ?? '');
            // Strip leading "v" for cleaner display + version_compare.
            $tag = ltrim($tag, 'vV');
            if ($tag === '') {
                continue;
            }
            $body = (string) ($r['body'] ?? '');
            $releases[] = [
                'tag'          => $tag,
                'name'         => (string) ($r['name'] ?? $tag),
                'body'         => $body,
                'body_html'    => self::renderBody($body),
                'published_at' => (string) ($r['published_at'] ?? ''),
                'html_url'     => (string) ($r['html_url'] ?? ''),
            ];
        }

        return $releases;
    }

    /**
     * Light-weight Markdown → HTML renderer for GitHub release notes. Intentionally
     * narrow: handles headings (#/##/###), bold, italic, inline code, links and
     * bullet lists. Anything beyond that renders as plain text. We HTML-escape
     * the input first so any `<script>` or stray HTML in the upstream body is
     * neutralised before our own tags are inserted.
     *
     * Public + static so it can be unit-tested without booting the cache.
     */
    public static function renderBody(string $body): string
    {
        if ($body === '') {
            return '';
        }

        $body  = str_replace(["\r\n", "\r"], "\n", $body);
        $lines = explode("\n", $body);

        $html      = '';
        $inUl      = false;
        $paraLines = [];

        $flushPara = function () use (&$paraLines, &$html): void {
            if ($paraLines === []) {
                return;
            }
            $text = self::renderInline(implode("\n", $paraLines));
            $text = nl2br($text, false);
            $html .= '<p style="margin:.4rem 0;">' . $text . '</p>';
            $paraLines = [];
        };

        $closeList = function () use (&$inUl, &$html): void {
            if ($inUl) {
                $html .= '</ul>';
                $inUl = false;
            }
        };

        foreach ($lines as $line) {
            if (preg_match('/^(#{1,3})\s+(.+)$/', $line, $m)) {
                $flushPara();
                $closeList();
                $level = strlen($m[1]);
                $tag   = ['h4', 'h5', 'h6'][$level - 1];
                $size  = [1 => '1.05rem', 2 => '.95rem', 3 => '.88rem'][$level];
                $html .= '<' . $tag . ' style="font-size:' . $size . ';font-weight:600;margin:.6rem 0 .2rem;">'
                    . self::renderInline($m[2])
                    . '</' . $tag . '>';
                continue;
            }
            if (preg_match('/^[\-\*]\s+(.+)$/', $line, $m)) {
                $flushPara();
                if (!$inUl) {
                    $html .= '<ul style="margin:.3rem 0 .3rem;padding-left:1.2rem;">';
                    $inUl = true;
                }
                $html .= '<li>' . self::renderInline($m[1]) . '</li>';
                continue;
            }
            if (trim($line) === '') {
                $flushPara();
                $closeList();
                continue;
            }
            if ($inUl) {
                $closeList();
            }
            $paraLines[] = $line;
        }

        $flushPara();
        $closeList();

        return $html;
    }

    private static function renderInline(string $text): string
    {
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $text = preg_replace(
            '/`([^`\n]+?)`/',
            '<code style="padding:1px 4px;background:rgba(99,102,241,.12);border-radius:3px;font-size:.85em;">$1</code>',
            $text
        );
        $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text);
        $text = preg_replace('/(?<!\*)\*([^\*\n]+?)\*(?!\*)/', '<em>$1</em>', $text);
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\((https?:\/\/[^\s\)]+)\)/',
            fn(array $m) => '<a href="' . $m[2] . '" target="_blank" rel="noopener">' . $m[1] . '</a>',
            $text
        );

        return $text;
    }
}
