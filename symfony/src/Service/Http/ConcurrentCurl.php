<?php

namespace App\Service\Http;

/**
 * Runs several ordinary, blocking-style tasks so the curl transfers they make
 * overlap — wall time ≈ the slowest task instead of the sum.
 *
 * How: each task runs in its own Fiber. Code on the task's path calls
 * ConcurrentCurl::exec($ch) where it would call curl_exec($ch); inside a fiber
 * this runner owns, exec() parks the fiber and hands the handle to one shared
 * curl_multi loop, which resumes the fiber with exactly what curl_exec() would
 * have returned (body string / false) once that transfer finishes. Everything
 * else the task does — config reads, circuit-breaker checks and writes,
 * response parsing, a second request after a login — runs unchanged, so a
 * task's observable behaviour is identical to running it on its own.
 *
 * Outside a runner-owned fiber, exec() IS curl_exec(): zero behaviour change
 * for every other caller of the same client code. A foreign fiber (someone
 * else's, or one left from an earlier request in FrankenPHP worker mode) is
 * never suspended.
 *
 * Worker-mode safety: the only static state is the set of fibers currently
 * driven by run(), emptied in run()'s finally (and weakly held, so a dropped
 * fiber can't pin memory). Nothing survives a run().
 */
class ConcurrentCurl
{
    /** @var \WeakMap<\Fiber<mixed, mixed, mixed, mixed>, true>|null fibers a run() is currently driving */
    private static ?\WeakMap $owned = null;

    /**
     * Drop-in for curl_exec(): cooperative inside a run(), plain otherwise.
     * The handle must be configured with CURLOPT_RETURNTRANSFER, as every
     * call site is.
     */
    public static function exec(\CurlHandle $ch): string|bool
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null || self::$owned === null || !isset(self::$owned[$fiber])) {
            return curl_exec($ch);
        }

        $result = \Fiber::suspend($ch);

        return is_string($result) || is_bool($result) ? $result : false;
    }

    /**
     * Run every task, overlapping their transfers. A task that throws only
     * fails itself. Results keep the task order.
     *
     * @param array<string, \Closure(): mixed> $tasks
     * @return array<string, array{value: mixed}|array{error: \Throwable}>
     */
    public function run(array $tasks): array
    {
        if ($tasks === []) {
            return [];
        }

        $owned   = self::$owned ??= new \WeakMap();
        $mh      = curl_multi_init();
        $out     = [];
        $fibers  = [];
        /** @var array<int, array{key: string, fiber: \Fiber<mixed, mixed, mixed, mixed>, ch: \CurlHandle}> $pending */
        $pending = [];

        try {
            foreach ($tasks as $key => $task) {
                $key   = (string) $key;
                $fiber = new \Fiber($task);
                $owned[$fiber] = true;
                $fibers[] = $fiber;
                $this->advance($key, $fiber, static fn () => $fiber->start(), $mh, $pending, $out);
            }

            $idle = 0;
            while ($pending !== []) {
                $status = curl_multi_exec($mh, $running);
                if ($status !== CURLM_OK) {
                    $this->finishSynchronously($mh, $pending, $out);
                    break;
                }

                $progressed = false;
                while (($info = curl_multi_info_read($mh)) !== false) {
                    if ($info['msg'] !== CURLMSG_DONE) {
                        continue;
                    }
                    $id = spl_object_id($info['handle']);
                    if (!isset($pending[$id])) {
                        continue;
                    }
                    ['key' => $key, 'fiber' => $fiber, 'ch' => $ch] = $pending[$id];
                    unset($pending[$id]);
                    curl_multi_remove_handle($mh, $ch);
                    $progressed = true;

                    // Same return contract as curl_exec(): false on any curl
                    // error, otherwise the body. curl_multi_info_read() has
                    // already stored the result on the handle, so the task's
                    // own curl_errno()/curl_error() read exactly as after a
                    // serial curl_exec().
                    $result = $info['result'] === CURLE_OK ? (curl_multi_getcontent($ch) ?? true) : false;
                    $this->advance($key, $fiber, static fn () => $fiber->resume($result), $mh, $pending, $out);
                }

                if ($pending === []) {
                    break;
                }
                if ($running > 0) {
                    if (curl_multi_select($mh, 1.0) === -1) {
                        usleep(1000);
                    }
                    $idle = 0;
                } elseif (!$progressed && ++$idle > 2) {
                    // Handles registered but libcurl reports nothing running
                    // and nothing finished — never spin; finish them serially.
                    $this->finishSynchronously($mh, $pending, $out);
                    break;
                }
            }
        } finally {
            foreach ($pending as $p) {
                curl_multi_remove_handle($mh, $p['ch']);
            }
            curl_multi_close($mh);
            foreach ($fibers as $fiber) {
                unset($owned[$fiber]);
            }
        }

        $ordered = [];
        foreach (array_keys($tasks) as $key) {
            $key = (string) $key;
            $ordered[$key] = $out[$key] ?? ['error' => new \LogicException("Task {$key} did not complete")];
        }
        return $ordered;
    }

    /**
     * Start/resume a task fiber and route what it does next: a transfer joins
     * the multi handle; completion or an exception is recorded.
     *
     * @param \Fiber<mixed, mixed, mixed, mixed> $fiber
     * @param \Closure(): mixed $step
     * @param array<int, array{key: string, fiber: \Fiber<mixed, mixed, mixed, mixed>, ch: \CurlHandle}> $pending
     * @param array<string, array{value: mixed}|array{error: \Throwable}> $out
     */
    private function advance(string $key, \Fiber $fiber, \Closure $step, \CurlMultiHandle $mh, array &$pending, array &$out, bool $multiplex = true): void
    {
        try {
            $suspended = $step();
            while (!$fiber->isTerminated()) {
                if ($multiplex && $suspended instanceof \CurlHandle && curl_multi_add_handle($mh, $suspended) === CURLM_OK) {
                    $pending[spl_object_id($suspended)] = ['key' => $key, 'fiber' => $fiber, 'ch' => $suspended];
                    // Kick the transfer off now (DNS/connect) rather than
                    // after every other task has been started.
                    curl_multi_exec($mh, $running);
                    return;
                }
                // Not something the loop can multiplex: a handle the multi
                // refused runs serially; any other suspension isn't ours.
                $suspended = $suspended instanceof \CurlHandle
                    ? $fiber->resume(curl_exec($suspended))
                    : $fiber->throw(new \LogicException('Unexpected fiber suspension inside ConcurrentCurl::run()'));
            }
            $out[$key] = ['value' => $fiber->getReturn()];
        } catch (\Throwable $e) {
            $out[$key] = ['error' => $e];
        }
    }

    /**
     * Fallback when the multi loop can't make progress: run every parked
     * transfer with plain curl_exec() and let its task carry on.
     *
     * @param array<int, array{key: string, fiber: \Fiber<mixed, mixed, mixed, mixed>, ch: \CurlHandle}> $pending
     * @param array<string, array{value: mixed}|array{error: \Throwable}> $out
     */
    private function finishSynchronously(\CurlMultiHandle $mh, array &$pending, array &$out): void
    {
        foreach ($pending as $id => $p) {
            unset($pending[$id]);
            curl_multi_remove_handle($mh, $p['ch']);
            $result = curl_exec($p['ch']);
            $fiber  = $p['fiber'];
            // multiplex=false: any further transfer of this task runs inline.
            $this->advance($p['key'], $fiber, static fn () => $fiber->resume($result), $mh, $pending, $out, false);
        }
    }
}
