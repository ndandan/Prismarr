<?php

namespace App\Service\Media;

use App\Exception\ServiceNotConfiguredException;
use App\Service\ConfigService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Deluge Web UI (deluge-web) JSON-RPC client.
 *
 * Every call is `POST {deluge_url}/json` and deluge-web answers HTTP 200
 * even on failure — the outcome lives in the body's `error` field, so
 * success is judged there, never on the HTTP status.
 *
 * Mirrors QBittorrentClient's conventions: circuit breaker (in-process +
 * cross-request via ServiceHealthCache), SSRF-guarded curl, lastError for
 * ApiClientErrorTrait, ResetInterface for FrankenPHP worker mode, and a
 * reverse-proxy mode (empty password) where auth is injected upstream.
 */
class DelugeClient implements ResetInterface
{
    private const SERVICE = 'Deluge';
    private const SERVICE_KEY = 'deluge';

    /**
     * Sentinel cookie in reverse-proxy mode (empty password in config —
     * the proxy injects auth on every request). login() returns it without
     * an auth.login round-trip; httpPost() skips the Cookie header for it.
     * Mirrors QBittorrentClient::NO_AUTH_SID (issue #10).
     */
    private const NO_AUTH_COOKIE = '__noauth__';

    /** qBit's "no ETA" sentinel — shared so the template formats both alike. */
    private const ETA_NONE = 8640000;

    /** deluge-web session as a ready-to-send "name=value" Cookie pair. */
    private ?string $cookie = null;

    /** web.connected verified once per request cycle. */
    private bool $daemonChecked = false;

    private string $baseUrl = '';
    private string $password = '';
    private int $rpcId = 0;

    /** @var array{code:int, method:string, path:string, message:string}|null */
    private ?array $lastError = null;

    /** Circuit breaker — see QBittorrentClient::$serviceUnavailable. */
    private bool $serviceUnavailable = false;

    public function __construct(
        private readonly ConfigService $config,
        private readonly LoggerInterface $logger,
        private readonly ServiceHealthCache $health,
    ) {}

    private function ensureConfig(): void
    {
        if ($this->config->get(self::SERVICE_KEY . '_enabled') === '0') {
            throw new ServiceNotConfiguredException(self::SERVICE, self::SERVICE_KEY . '_enabled');
        }
        if ($this->baseUrl === '') {
            $this->baseUrl  = $this->config->require('deluge_url', self::SERVICE);
            $this->password = (string) ($this->config->get('deluge_password') ?? '');
        }
    }

    public function reset(): void
    {
        $this->baseUrl = '';
        $this->password = '';
        $this->cookie = null;
        $this->daemonChecked = false;
        $this->rpcId = 0;
        $this->lastError = null;
        $this->serviceUnavailable = false;
    }

    /** @return array{code:int, method:string, path:string, message:string}|null */
    public function getLastError(): ?array
    {
        return $this->lastError;
    }

    private function recordError(string $method, int $code, string $message): void
    {
        $this->lastError = ['code' => $code, 'method' => $method, 'path' => '/json', 'message' => $message];
    }

    /** Light ping — true if deluge-web answers and accepts the password. */
    public function ping(): bool
    {
        try {
            return $this->getVersion() !== null;
        } catch (\Throwable $e) {
            $this->logger->warning('Deluge ping failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return false;
        }
    }

    public function getVersion(): ?string
    {
        $v = $this->result('daemon.get_version');
        return is_string($v) ? $v : null;
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  JSON-RPC transport
    // ══════════════════════════════════════════════════════════════════════════

    /** Convenience: RPC result or null on failure (check getLastError()). */
    private function result(string $method, array $params = []): mixed
    {
        return $this->call($method, $params)['result'];
    }

    /** Convenience: true when the RPC succeeded (result may be null). */
    private function ok(string $method, array $params = []): bool
    {
        return $this->call($method, $params)['ok'];
    }

    /**
     * Perform one JSON-RPC call. Success means "no error in the envelope";
     * many Deluge mutations legitimately return result null, hence the
     * separate ok flag.
     *
     * @return array{ok: bool, result: mixed}
     */
    private function call(string $method, array $params = [], bool $retried = false): array
    {
        $failure = ['ok' => false, 'result' => null];
        if ($this->health->isDown(self::SERVICE_KEY)) {
            $this->serviceUnavailable = true;
            return $failure;
        }
        if ($this->serviceUnavailable) {
            return $failure;
        }
        $this->lastError = null;

        $cookie = $this->login();
        if ($cookie === null) {
            return $failure;
        }

        // deluge-web can be up while disconnected from the deluged daemon —
        // core.* calls then fail with an unhelpful error. Verify once per
        // request cycle and auto-connect to the first known host.
        if (!$this->daemonChecked && !str_starts_with($method, 'web.')) {
            $this->daemonChecked = true; // set first — ensureDaemonConnected() re-enters call()
            if (!$this->ensureDaemonConnected()) {
                return $failure;
            }
        }

        $payload = json_encode(['method' => $method, 'params' => $params, 'id' => ++$this->rpcId]);
        $resp = $this->httpPost($payload, $cookie);

        if ($resp['body'] === false) {
            $this->logger->warning('DelugeClient RPC error', ['method' => $method, 'error' => $resp['err']]);
            $this->recordError($method, $resp['code'], $resp['err'] !== '' ? $resp['err'] : 'connection failed');
            $this->serviceUnavailable = true;
            $this->health->markDown(self::SERVICE_KEY);
            return $failure;
        }
        if ($resp['code'] < 200 || $resp['code'] >= 300) {
            $this->logger->warning('DelugeClient RPC HTTP error', ['method' => $method, 'code' => $resp['code']]);
            $this->recordError($method, $resp['code'], 'HTTP ' . $resp['code']);
            if ($resp['code'] === 0) {
                $this->serviceUnavailable = true;
                $this->health->markDown(self::SERVICE_KEY);
            }
            return $failure;
        }

        $parsed = self::parseRpcBody($resp['body']);
        if ($parsed['error'] !== null) {
            // code 1 = "Not authenticated" → expired session → one re-login retry
            if (($parsed['error']['code'] ?? null) === 1 && !$retried && $cookie !== self::NO_AUTH_COOKIE) {
                $this->cookie = null;
                return $this->call($method, $params, true);
            }
            $this->recordError($method, $resp['code'], $parsed['error']['message']);
            return $failure;
        }

        $this->health->clear(self::SERVICE_KEY);
        return ['ok' => true, 'result' => $parsed['result']];
    }

    /**
     * Decode a JSON-RPC envelope. deluge-web always answers HTTP 200, so
     * this is the single place success/failure is decided.
     *
     * @return array{result: mixed, error: ?array{code: ?int, message: string}}
     */
    private static function parseRpcBody(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['result' => null, 'error' => ['code' => null, 'message' => 'malformed JSON-RPC response']];
        }
        $err = $decoded['error'] ?? null;
        if ($err !== null) {
            return ['result' => null, 'error' => [
                'code'    => is_array($err) && isset($err['code']) ? (int) $err['code'] : null,
                'message' => is_array($err) ? (string) ($err['message'] ?? 'RPC error') : (string) $err,
            ]];
        }
        return ['result' => $decoded['result'] ?? null, 'error' => null];
    }

    private function ensureDaemonConnected(): bool
    {
        $connected = $this->call('web.connected');
        if ($connected['ok'] && $connected['result'] === true) {
            return true;
        }
        $hosts = $this->call('web.get_hosts');
        $hostId = is_array($hosts['result'] ?? null) && isset($hosts['result'][0][0])
            ? (string) $hosts['result'][0][0]
            : null;
        if ($hostId !== null) {
            $this->call('web.connect', [$hostId]);
            $again = $this->call('web.connected');
            if ($again['ok'] && $again['result'] === true) {
                return true;
            }
        }
        $this->logger->warning('DelugeClient: web UI is not connected to the daemon');
        $this->recordError('web.connected', 0, 'Deluge web UI is not connected to the daemon');
        $this->serviceUnavailable = true;
        return false;
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Authentication
    // ══════════════════════════════════════════════════════════════════════════

    private function login(): ?string
    {
        if ($this->cookie !== null) {
            return $this->cookie;
        }
        $this->ensureConfig();

        // Reverse-proxy mode: empty password means the proxy in front of
        // deluge-web injects the session — auth.login with '' would fail.
        if ($this->password === '') {
            $this->cookie = self::NO_AUTH_COOKIE;
            $this->health->clear(self::SERVICE_KEY);
            return $this->cookie;
        }

        $payload = json_encode(['method' => 'auth.login', 'params' => [$this->password], 'id' => ++$this->rpcId]);
        $resp = $this->httpPost($payload, null);

        if ($resp['body'] === false || $resp['code'] === 0) {
            $this->logger->warning('DelugeClient login error', ['error' => $resp['err']]);
            $this->recordError('auth.login', $resp['code'], $resp['err'] !== '' ? $resp['err'] : 'connection failed');
            $this->serviceUnavailable = true;
            $this->health->markDown(self::SERVICE_KEY);
            return null;
        }

        $parsed = self::parseRpcBody($resp['body']);
        if ($parsed['result'] !== true) {
            // Wrong password: HTTP 200 with result false — host is reachable,
            // so do NOT trip the circuit breaker.
            $this->recordError('auth.login', $resp['code'], $parsed['error']['message'] ?? 'wrong password');
            return null;
        }

        $cookie = self::extractSessionCookie($resp['headers']);
        if ($cookie === null) {
            $this->recordError('auth.login', $resp['code'], 'no session cookie in login response');
            return null;
        }
        $this->cookie = $cookie;
        $this->health->clear(self::SERVICE_KEY);
        return $this->cookie;
    }

    /** Extract `_session_id` from raw response headers as a "name=value" pair. */
    private static function extractSessionCookie(string $responseHeaders): ?string
    {
        if (preg_match('/Set-Cookie:\s*(_session_id)=([^;\s]+)/i', $responseHeaders, $m)) {
            return $m[1] . '=' . $m[2];
        }
        return null;
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  HTTP
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * POST a JSON payload to /json. Always captures response headers (login
     * needs Set-Cookie). Timeouts + NOSIGNAL as in QBittorrentClient — see
     * the SIGALRM note there for why NOSIGNAL is critical in worker mode.
     *
     * @return array{body: string|false, headers: string, code: int, err: string}
     */
    private function httpPost(string $json, ?string $cookie): array
    {
        $this->ensureConfig();
        $url = rtrim($this->baseUrl, '/') . '/json';

        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($cookie !== null && $cookie !== self::NO_AUTH_COOKIE) {
            $headers[] = 'Cookie: ' . $cookie;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS, // SSRF guard
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT  => 4,
            CURLOPT_TIMEOUT         => 8,
            CURLOPT_NOSIGNAL        => 1,
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => $json,
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_HEADER          => true,
        ]);

        $raw   = curl_exec($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $err   = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['body' => false, 'headers' => '', 'code' => $code, 'err' => $err];
        }
        return ['body' => substr($raw, $hsize), 'headers' => substr($raw, 0, $hsize), 'code' => $code, 'err' => $err];
    }
}
