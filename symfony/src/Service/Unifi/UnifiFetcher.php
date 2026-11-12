<?php

namespace App\Service\Unifi;

/**
 * Transport seam over the classic UniFi Network API. Exists so the readers in
 * this namespace can be unit-tested against canned payloads with no cURL and
 * no network, while the single real implementation (UnifiClient) keeps sole
 * ownership of config, TLS policy, headers and the ok-envelope contract.
 *
 * Read-only by construction: callers pass a stat/rest/list path. $body turns
 * the call into a POST, which the classic API requires for report queries —
 * it is never used to mutate.
 */
interface UnifiFetcher
{
    /**
     * At most one API call. Returns the ok-envelope's `data` list, or null on
     * transport error / non-200 / error envelope — never throws.
     *
     * "At most": UnifiClient answers a repeated GET to the same path from a
     * short memo (a few seconds, well under any poll cadence) so readers
     * batched into one request share a console call — a success is reused,
     * and so is a transport failure, which comes back as null with
     * transportFailed() raised. POSTs always make the call.
     *
     * @param  ?array $body  when non-null, sent as a JSON POST body
     * @return ?array<mixed>
     */
    public function fetch(string $path, ?array $body = null): ?array;

    /**
     * At most one call to the v2 API (<url>/proxy/network/v2/api/site/<site>$path,
     * query string included in $path). The v2 API has no ok-envelope: returns
     * the decoded JSON body as-is, or null on transport error / non-200 /
     * non-JSON — never throws. Sets transportFailed() like fetch().
     *
     * Same read-only contract as fetch(): $body turns it into a POST, which
     * some v2 queries (app-traffic-rate) require; a GET is memoised exactly
     * like a classic GET, a POST never.
     *
     * Network 11.x moved history to v2 (see WanActivity, UnifiSparkReader).
     *
     * @param  ?array $body when non-null, sent as a JSON POST body
     * @return ?array<mixed>
     */
    public function fetchV2(string $path, ?array $body = null): ?array;

    /**
     * True when the MOST RECENT fetch() failed at the transport layer
     * (connect refused, DNS failure, timeout) rather than returning an HTTP
     * or application-level error. Readers use this to skip their remaining
     * calls against a dead console instead of burning one connect timeout
     * per endpoint. Re-evaluated by every fetch(), memo answers included.
     *
     * @phpstan-impure its answer changes with every fetch()/fetchV2()
     */
    public function transportFailed(): bool;

    /**
     * A stable id of the console this fetcher talks to (URL + site + key),
     * used to key the readers' shared cache so a settings change never serves
     * the previous console's data. '' when UniFi is not configured.
     */
    public function cacheIdentity(): string;
}
