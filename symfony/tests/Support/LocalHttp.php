<?php

namespace App\Tests\Support;

/**
 * Local network fixtures for tests that need REAL curl transfers with a known
 * duration, without touching anything off-box:
 *
 *  - blackhole(): a listening socket that never accepts. The TCP handshake
 *    completes in the kernel backlog, the request is sent, no reply ever
 *    comes — so a handle with TIMEOUT_MS=N fails with CURLE_OPERATION_TIMEDOUT
 *    after ~N ms, deterministically.
 *  - serverUrl(): PHP's built-in server (forking workers) whose router
 *    sleeps ?ms= (or a /d<ms> path prefix) then answers ?code= with body
 *    ?body= (default "{}") — the success-path twin.
 */
final class LocalHttp
{
    /** @var resource|null */
    private static $blackhole = null;
    private static ?string $blackholeUrl = null;

    /** @var resource|null */
    private static $serverProc = null;
    private static ?string $serverUrl = null;

    public static function blackholeUrl(): string
    {
        if (self::$blackholeUrl === null) {
            $ctx = stream_context_create(['socket' => ['backlog' => 128]]);
            $srv = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
            if ($srv === false) {
                throw new \RuntimeException("blackhole socket: {$errstr}");
            }
            self::$blackhole    = $srv;
            self::$blackholeUrl = 'http://' . stream_socket_get_name($srv, false) . '/';
        }
        return self::$blackholeUrl;
    }

    /** A handle that times out after ~$timeoutMs against the blackhole. */
    public static function blackholeHandle(int $timeoutMs): \CurlHandle
    {
        $ch = curl_init(self::blackholeUrl());
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT_MS     => $timeoutMs,
            CURLOPT_NOSIGNAL       => true,
        ]);
        return $ch;
    }

    /** Base URL of the sleeping built-in server, or null when it can't start here. */
    public static function serverUrl(): ?string
    {
        if (self::$serverUrl !== null) {
            return self::$serverUrl;
        }
        if (!function_exists('proc_open')) {
            return null;
        }
        $dir = sys_get_temp_dir() . '/prismarr-localhttp-' . getmypid();
        @mkdir($dir);
        file_put_contents($dir . '/router.php', <<<'PHP'
            <?php
            // Delay from ?ms= or from a /d<ms> path prefix (for clients that
            // append their own API path to a configured base URL).
            $ms = $_GET['ms'] ?? (preg_match('#^/d(\d+)#', $_SERVER['REQUEST_URI'], $m) ? $m[1] : 0);
            usleep(max(0, (int) $ms) * 1000);
            http_response_code((int) ($_GET['code'] ?? 200));
            echo $_GET['body'] ?? '{}';
            PHP);

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        if ($probe === false) {
            return null;
        }
        $addr = stream_socket_get_name($probe, false);
        fclose($probe);

        $proc = proc_open(
            [PHP_BINARY, '-S', $addr, $dir . '/router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            $dir,
            ['PHP_CLI_SERVER_WORKERS' => '16'] + getenv(),
        );
        if (!is_resource($proc)) {
            return null;
        }
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $sock = @stream_socket_client('tcp://' . $addr, $e, $s, 0.2);
            if ($sock !== false) {
                fclose($sock);
                self::$serverProc = $proc;
                self::$serverUrl  = 'http://' . $addr . '/';
                register_shutdown_function(static function () use ($dir): void {
                    if (is_resource(self::$serverProc)) {
                        proc_terminate(self::$serverProc);
                    }
                    @unlink($dir . '/router.php');
                    @rmdir($dir);
                });
                return self::$serverUrl;
            }
            usleep(50_000);
        }
        proc_terminate($proc);
        return null;
    }

    /** A handle against the built-in server that answers after ~$ms. */
    public static function serverHandle(string $base, int $ms, int $code = 200, string $body = 'ok'): \CurlHandle
    {
        $ch = curl_init($base . '?' . http_build_query(['ms' => $ms, 'code' => $code, 'body' => $body]));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_NOSIGNAL       => true,
        ]);
        return $ch;
    }
}
