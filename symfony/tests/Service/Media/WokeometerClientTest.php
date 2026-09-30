<?php

namespace App\Tests\Service\Media;

use App\Service\ConfigService;
use App\Service\Media\WokeometerClient;
use App\Service\Wokeometer\WokeometerPageResult;
use App\Service\Wokeometer\WokeometerSettings;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Service\ResetInterface;

/**
 * WokeometerClient is exercised entirely through its protected exec() seam:
 * each fake returns a fabricated raw response (header block + body, split
 * by headerSize exactly like CURLOPT_HEADER output) — no network I/O, and
 * no test can ever spend a credit.
 */
#[AllowMockObjectsWithoutExpectations]
class WokeometerClientTest extends TestCase
{
    private const KEY  = 'wok_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    private const IDEM = '8f14e45f-ceea-467a-9f4b-6d6c1e2a0b11';

    /** 2026-09-01T00:00:00Z */
    private const SEP_2026 = 1788220800;

    /** The fake client's clock (2026-09-21T13:46:40Z) — see now() in client(). */
    public const NOW = 1_790_000_000;

    /** @var array<string, string> */
    private array $values = [];

    /** @var list<string> */
    private array $urls = [];

    /** @var list<list<string>> */
    private array $sentHeaders = [];

    private int $calls = 0;

    /** Stored-key reads through ConfigService (secret-redaction read budget). */
    private int $keyReads = 0;

    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->values      = ['wokeometer_api_key' => self::KEY];
        $this->urls        = [];
        $this->sentHeaders = [];
        $this->calls       = 0;
        $this->keyReads    = 0;
        $this->logs        = [];
    }

    private function settings(): WokeometerSettings
    {
        $c = $this->createMock(ConfigService::class);
        // Read through $this->values at call time so a test can change the
        // stored settings mid-test (reset() coverage).
        $c->method('get')->willReturnCallback(function (string $k) {
            if ($k === WokeometerSettings::KEY_API_KEY) {
                $this->keyReads++;
            }

            return ($this->values[$k] ?? '') !== '' ? $this->values[$k] : null;
        });

        return new WokeometerSettings($c);
    }

    private function recordingLogger(): LoggerInterface
    {
        return new class ($this->logs) extends AbstractLogger {
            /** @param list<array{level: mixed, message: string, context: array<string, mixed>}> $records */
            public function __construct(private array &$records) {}

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }

    /**
     * @param list<array{body: string|false, code: int, error: string, headerSize: int}> $responses
     */
    private function client(array $responses = [], ?LoggerInterface $logger = null): WokeometerClient
    {
        return new class (
            $this->settings(),
            $logger ?? $this->recordingLogger(),
            $responses,
            $this->urls,
            $this->sentHeaders,
            $this->calls,
        ) extends WokeometerClient {
            /**
             * @param list<array{body: string|false, code: int, error: string, headerSize: int}> $responses
             * @param list<string>       $urls
             * @param list<list<string>> $sentHeaders
             */
            public function __construct(
                WokeometerSettings $settings,
                LoggerInterface $logger,
                private array $responses,
                private array &$urls,
                private array &$sentHeaders,
                private int &$calls,
            ) {
                parent::__construct($settings, $logger);
            }

            protected function requestHeaders(string $apiKey, string $idempotencyKey): array
            {
                $headers = parent::requestHeaders($apiKey, $idempotencyKey);
                $this->sentHeaders[] = $headers;

                return $headers;
            }

            protected function now(): int
            {
                return WokeometerClientTest::NOW;
            }

            protected function exec(\CurlHandle $ch): array
            {
                $this->calls++;
                $this->urls[] = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

                $next = array_shift($this->responses);
                if ($next === null) {
                    throw new \LogicException('exec() called more times than responses were scripted');
                }

                return $next;
            }
        };
    }

    /**
     * @param array<string, string> $headers
     * @return array{body: string, code: int, error: string, headerSize: int}
     */
    private static function response(int $code, string $body, array $headers = [], string $preamble = ''): array
    {
        $block = "HTTP/2 {$code}\r\n";
        foreach ($headers as $name => $value) {
            $block .= "{$name}: {$value}\r\n";
        }
        $block = $preamble . $block . "\r\n";

        return ['body' => $block . $body, 'code' => $code, 'error' => '', 'headerSize' => strlen($block)];
    }

    /** @param array<string, mixed> $envelope */
    private static function json(array $envelope): string
    {
        return (string) json_encode($envelope);
    }

    // ------------------------------------------------------------------
    // Readiness / no-request paths
    // ------------------------------------------------------------------

    public function testUnconfiguredMakesNoRequest(): void
    {
        $this->values = [];
        $client = $this->client();

        $this->assertFalse($client->ready());
        $result = $client->listMedia('movie', null, null, self::IDEM);

        $this->assertSame(WokeometerPageResult::UNCONFIGURED, $result->outcome);
        $this->assertSame(0, $this->calls, 'no request may be made when unconfigured');
        $this->assertSame([], $this->sentHeaders);
    }

    public function testDisabledWithKeyMakesNoRequest(): void
    {
        $this->values['wokeometer_enabled'] = '0';
        $client = $this->client();

        $this->assertFalse($client->ready());
        $this->assertSame(WokeometerPageResult::UNCONFIGURED, $client->listMedia('tv', null, null, self::IDEM)->outcome);
        $this->assertSame(0, $this->calls);
    }

    public function testReadyWhenEnabledWithKey(): void
    {
        $this->assertTrue($this->client()->ready());
    }

    public function testUnsupportedTypeIsInvalidWithoutRequest(): void
    {
        $result = $this->client()->listMedia('game', null, null, self::IDEM);

        $this->assertSame(WokeometerPageResult::INVALID, $result->outcome);
        $this->assertSame(0, $this->calls);
    }

    public function testIdempotencyKeyWithHeaderInjectionIsInvalidWithoutRequest(): void
    {
        $result = $this->client()->listMedia('movie', null, null, "abc\r\nX-Evil: 1");

        $this->assertSame(WokeometerPageResult::INVALID, $result->outcome);
        $this->assertSame(0, $this->calls);
    }

    public function testKeyWithControlCharactersIsNotReady(): void
    {
        $this->values['wokeometer_api_key'] = "wok_abc\r\nX-Evil: 1";
        $client = $this->client();

        $this->assertFalse($client->ready());
        $this->assertSame(WokeometerPageResult::UNCONFIGURED, $client->listMedia('movie', null, null, self::IDEM)->outcome);
        $this->assertSame(0, $this->calls);
    }

    public function testImplementsResetInterfaceAndResetDropsTheConfigMemo(): void
    {
        $this->values = [];
        $client = $this->client();
        $this->assertInstanceOf(ResetInterface::class, $client);

        $this->assertFalse($client->ready());
        // Admin saves a key: the memo keeps the per-request view…
        $this->values = ['wokeometer_api_key' => self::KEY];
        $this->assertFalse($client->ready());

        // …until the worker's services_resetter calls reset() between messages.
        $client->reset();
        $this->assertTrue($client->ready());
    }

    // ------------------------------------------------------------------
    // Request shape
    // ------------------------------------------------------------------

    public function testQueryContainsExactlyTheDocumentedParams(): void
    {
        $client = $this->client([self::response(200, self::json(['data' => [], 'next_cursor' => null]))]);
        $client->listMedia('tv', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', self::SEP_2026, self::IDEM);

        $this->assertCount(1, $this->urls);
        $url = $this->urls[0];
        $this->assertStringStartsWith('https://wokeometer.app/api/v1/media?', $url);
        $this->assertStringContainsString('updated_since=2026-09-01T00%3A00%3A00Z', $url);
        $this->assertStringNotContainsString(self::KEY, $url);
        $this->assertStringNotContainsString(self::KEY, urldecode($url));

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame([
            'type'          => 'tv',
            'limit'         => '50',
            'after'         => '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b',
            'updated_since' => '2026-09-01T00:00:00Z',
        ], $query);
    }

    public function testQueryOmitsAfterAndUpdatedSinceWhenNull(): void
    {
        $client = $this->client([self::response(200, self::json(['data' => [], 'next_cursor' => null]))]);
        $client->listMedia('movie', null, null, self::IDEM);

        parse_str((string) parse_url($this->urls[0], PHP_URL_QUERY), $query);
        $this->assertSame(['type' => 'movie', 'limit' => '50'], $query);
    }

    public function testHeadersCarryBearerAcceptAndIdempotencyKey(): void
    {
        $client = $this->client([self::response(200, self::json(['data' => [], 'next_cursor' => null]))]);
        $client->listMedia('movie', null, null, self::IDEM);

        $this->assertSame([[
            'Authorization: Bearer ' . self::KEY,
            'Accept: application/json',
            'Idempotency-Key: ' . self::IDEM,
        ]], $this->sentHeaders);
    }

    // ------------------------------------------------------------------
    // 200 parsing
    // ------------------------------------------------------------------

    public function testOkPageParsesHeadersCursorAndNormalizesRows(): void
    {
        $body = self::json([
            'data' => [
                [
                    'id'              => 'aaaaaaaa-0000-4000-8000-000000000001',
                    'title'           => 'The Matrix',
                    'media_type'      => 'movie',
                    'release_date'    => '1999-03-31',
                    'woke_score'      => 0,
                    'tldr'            => 'Red pill.',
                    'poster_url'      => 'https://img.example/p.jpg',
                    'slug'            => 'the-matrix',
                    'is_analyzed'     => true,
                    'last_updated'    => '2026-02-01T18:00:14.473+00:00',
                    'parent_id'       => null,
                    'season_number'   => null,
                    'collection_id'   => null,
                    'external_source' => ' TMDb ',
                    'external_id'     => '603',
                    'audience_score'  => 4.2,
                    'some_future_key' => ['x' => 1],
                ],
                [
                    'id'              => 'aaaaaaaa-0000-4000-8000-000000000002',
                    'title'           => 'Season 2',
                    'media_type'      => 'tv',
                    'release_date'    => '2020-05-17T00:00:00Z',
                    'woke_score'      => null,
                    'tldr'            => null,
                    'slug'            => null,
                    'is_analyzed'     => null,
                    'last_updated'    => 'not a date',
                    'parent_id'       => 'aaaaaaaa-0000-4000-8000-0000000000ff',
                    'season_number'   => 2,
                    'external_source' => 'tmdb',
                    'external_id'     => 'tt0133093',
                ],
                // No id → dropped.
                ['title' => 'Ghost', 'media_type' => 'movie', 'woke_score' => 5],
                // Not an object → dropped.
                'garbage',
                [
                    'id'              => 'aaaaaaaa-0000-4000-8000-000000000003',
                    'woke_score'      => 11,          // out of range → null
                    'season_number'   => '3',          // numeric string → coerced
                    'is_analyzed'     => 'yes',        // unknown shape → null
                    'external_source' => 'igdb',
                    'external_id'     => '1234',       // numeric but not tmdb → no tmdbId
                    'last_updated'    => '',           // empty → 0 (never "now")
                ],
                [
                    'id'              => 'aaaaaaaa-0000-4000-8000-000000000004',
                    'title'           => 'Zero id',
                    'woke_score'      => '7',
                    'external_source' => 'tmdb',
                    'external_id'     => '0',          // not > 0 → null
                    'last_updated'    => '2026-09-01T00:00:00Z',
                ],
                // Present media_type is trimmed + lowercased.
                ['id' => 'aaaaaaaa-0000-4000-8000-000000000005', 'title' => 'Cased', 'media_type' => ' Movie '],
                // Present media_type outside movie|tv → dropped (counted).
                ['id' => 'aaaaaaaa-0000-4000-8000-000000000006', 'title' => 'A game', 'media_type' => 'game'],
                // Present but non-string media_type → dropped too.
                ['id' => 'aaaaaaaa-0000-4000-8000-000000000007', 'title' => 'Odd', 'media_type' => ['movie']],
            ],
            'request_id'      => 'req-1',
            'credits_charged' => 1,
            'next_cursor'     => 'aaaaaaaa-0000-4000-8000-000000000004',
        ]);

        $client = $this->client([self::response(200, $body, [
            'Content-Type'            => 'application/json',
            'X-API-Credits-Remaining' => '941',
            'X-API-Credits-Charged'   => '1',
            'Idempotency-Replayed'    => 'false',
        ])]);
        $result = $client->listMedia('movie', null, null, self::IDEM);

        $this->assertSame(WokeometerPageResult::OK, $result->outcome);
        $this->assertTrue($result->isOk());
        $this->assertSame(200, $result->httpCode);
        $this->assertSame('aaaaaaaa-0000-4000-8000-000000000004', $result->nextCursor);
        $this->assertSame(941, $result->creditsRemaining);
        $this->assertSame(1, $result->creditsCharged);
        $this->assertFalse($result->replayed);
        $this->assertNull($result->retryAfter);
        $this->assertNull($result->message);
        $this->assertCount(5, $result->rows, 'id-less, non-object and foreign-type rows are dropped');
        $this->assertSame(4, $result->droppedRows, 'every dropped entry is counted, the page stays ok');

        [$matrix, $season, $odd, $zero, $cased] = $result->rows;
        $this->assertSame('movie', $cased['mediaType'], '" Movie " → movie');

        $this->assertSame([
            'wokeometerId'       => 'aaaaaaaa-0000-4000-8000-000000000001',
            'mediaType'          => 'movie',
            'title'              => 'The Matrix',
            'releaseDate'        => '1999-03-31',
            'wokeScore'          => 0,
            'tldr'               => 'Red pill.',
            'slug'               => 'the-matrix',
            'isAnalyzed'         => true,
            'lastUpdated'        => 1769968814,
            'parentWokeometerId' => null,
            'seasonNumber'       => null,
            'externalSource'     => 'tmdb',
            'externalId'         => '603',
            'tmdbId'             => 603,
        ], $matrix, 'TMDb is lowercased/trimmed, score 0 is kept, unknown keys are ignored');

        $this->assertSame('tv', $season['mediaType']);
        $this->assertSame('2020-05-17', $season['releaseDate'], 'release_date cut to 10 chars');
        $this->assertNull($season['wokeScore'], 'null score stays null');
        $this->assertNull($season['tldr']);
        $this->assertNull($season['slug']);
        $this->assertNull($season['isAnalyzed']);
        $this->assertSame(0, $season['lastUpdated'], 'unparseable date → 0');
        $this->assertSame('aaaaaaaa-0000-4000-8000-0000000000ff', $season['parentWokeometerId']);
        $this->assertSame(2, $season['seasonNumber']);
        $this->assertSame('tmdb', $season['externalSource']);
        $this->assertSame('tt0133093', $season['externalId']);
        $this->assertNull($season['tmdbId'], 'non-numeric external id → no tmdb id');

        $this->assertSame('movie', $odd['mediaType'], 'missing media_type falls back to the requested type');
        $this->assertSame('', $odd['title'], 'missing title → empty string');
        $this->assertNull($odd['releaseDate']);
        $this->assertNull($odd['wokeScore'], 'score outside 0-10 → null');
        $this->assertSame(3, $odd['seasonNumber']);
        $this->assertNull($odd['isAnalyzed']);
        $this->assertSame('igdb', $odd['externalSource']);
        $this->assertNull($odd['tmdbId']);
        $this->assertSame(0, $odd['lastUpdated'], 'empty date → 0, never the current time');

        $this->assertSame(7, $zero['wokeScore'], 'numeric-string score coerced');
        $this->assertNull($zero['tmdbId'], 'tmdb id must be > 0');
        $this->assertSame(self::SEP_2026, $zero['lastUpdated']);
    }

    /** @return iterable<string, array{0: mixed, 1: ?int}> */
    public static function seasonNumberShapes(): iterable
    {
        yield 'zero (live API on non-season rows)' => [0, null];
        yield 'one'                                => [1, 1];
        yield 'numeric string'                     => ['3', 3];
        yield 'zero string'                        => ['0', null];
        yield 'negative'                           => [-1, null];
        yield 'null'                               => [null, null];
        yield 'non-numeric'                        => ['abc', null];
    }

    #[DataProvider('seasonNumberShapes')]
    public function testSeasonNumberZeroOrInvalidMeansNotASeason(mixed $raw, ?int $expected): void
    {
        $body = self::json(['data' => [[
            'id'              => 'aaaaaaaa-0000-4000-8000-0000000000aa',
            'media_type'      => 'movie',
            'season_number'   => $raw,
            'external_source' => 'tmdb',
            'external_id'     => '603',
        ]], 'next_cursor' => null]);
        $result = $this->client([self::response(200, $body)])->listMedia('movie', null, null, self::IDEM);

        $this->assertSame($expected, $result->rows[0]['seasonNumber']);
    }

    public function testAbsentSeasonNumberIsNull(): void
    {
        $body = self::json(['data' => [[
            'id'         => 'aaaaaaaa-0000-4000-8000-0000000000ab',
            'media_type' => 'movie',
        ]], 'next_cursor' => null]);
        $result = $this->client([self::response(200, $body)])->listMedia('movie', null, null, self::IDEM);

        $this->assertNull($result->rows[0]['seasonNumber']);
    }

    /** @return iterable<string, array{0: mixed, 1: int}> */
    public static function lastUpdatedShapes(): iterable
    {
        yield 'ATOM +00:00'          => ['2026-02-01T18:00:14+00:00', 1769968800 + 14];
        yield 'ATOM Z'               => ['2026-09-01T00:00:00Z', self::SEP_2026];
        yield 'ATOM offset'          => ['2026-02-01T20:00:14+02:00', 1769968814];
        yield 'milliseconds'         => ['2026-02-01T18:00:14.473+00:00', 1769968814];
        yield 'microseconds Z'       => ['2026-02-01T18:00:14.473123Z', 1769968814];
        yield 'SQL datetime (UTC)'   => ['2026-02-01 18:00:14', 1769968814];
        yield 'date only (UTC)'      => ['2026-02-01', 1769904000];
        yield 'relative +1 year'     => ['+1 year', 0];
        yield 'relative now'         => ['now', 0];
        yield 'relative tomorrow'    => ['tomorrow', 0];
        yield 'invalid calendar day' => ['2024-02-31', 0];
        yield 'garbage'              => ['not a date', 0];
        yield 'epoch number'         => [1769968814, 0];
        yield 'far future ISO'       => ['2999-01-01T00:00:00Z', self::NOW + 86_400];
        yield 'two days ahead'       => [gmdate('Y-m-d\TH:i:s\Z', self::NOW + 2 * 86_400), self::NOW + 86_400];
        yield 'twelve hours ahead'   => [gmdate('Y-m-d\TH:i:s\Z', self::NOW + 43_200), self::NOW + 43_200];
    }

    #[DataProvider('lastUpdatedShapes')]
    public function testLastUpdatedIsParsedStrictlyAndClampedToTomorrow(mixed $value, int $expected): void
    {
        $body = self::json(['data' => [['id' => 'x-1', 'title' => 'T', 'last_updated' => $value]], 'next_cursor' => null]);
        $result = $this->client([self::response(200, $body)])->listMedia('movie', null, null, self::IDEM);

        $this->assertSame(WokeometerPageResult::OK, $result->outcome);
        $this->assertSame($expected, $result->rows[0]['lastUpdated']);
    }

    public function testNullNextCursorAndReplayHeadersFromTheLastHeaderBlock(): void
    {
        // A 100-continue block precedes the real one; its (bogus) credit
        // header must not win. Header names are matched case-insensitively.
        $response = self::response(
            200,
            self::json(['data' => [], 'request_id' => 'r', 'credits_charged' => 1, 'next_cursor' => null]),
            ['x-api-credits-remaining' => '12', 'X-Api-Credits-Charged' => '0', 'idempotency-replayed' => 'TRUE'],
            "HTTP/1.1 100 Continue\r\nX-API-Credits-Remaining: 99999\r\n\r\n",
        );
        $result = $this->client([$response])->listMedia('tv', 'cursor', null, self::IDEM);

        $this->assertSame(WokeometerPageResult::OK, $result->outcome);
        $this->assertNull($result->nextCursor);
        $this->assertSame([], $result->rows);
        $this->assertSame(12, $result->creditsRemaining);
        $this->assertSame(0, $result->creditsCharged);
        $this->assertTrue($result->replayed);
    }

    public function testOkWithoutCreditHeadersLeavesThemNull(): void
    {
        $result = $this->client([self::response(200, self::json(['data' => [], 'next_cursor' => null]))])
            ->listMedia('movie', null, null, self::IDEM);

        $this->assertSame(WokeometerPageResult::OK, $result->outcome);
        $this->assertNull($result->creditsRemaining);
        $this->assertNull($result->creditsCharged);
        $this->assertFalse($result->replayed);
        $this->assertSame(0, $result->droppedRows, 'an empty page drops nothing and stays ok');
    }

    public function testPageWhereEveryEntryIsDroppedIsInvalid(): void
    {
        $body = self::json([
            'data' => [
                ['title' => 'no id', 'media_type' => 'movie'],
                ['id' => 'aaaaaaaa-0000-4000-8000-000000000009', 'media_type' => 'book'],
                'garbage',
            ],
            'next_cursor' => 'aaaaaaaa-0000-4000-8000-000000000009',
        ]);
        $result = $this->client([self::response(200, $body, ['X-API-Credits-Remaining' => '10'])])
            ->listMedia('movie', null, null, self::IDEM);

        $this->assertSame(WokeometerPageResult::INVALID, $result->outcome, 'schema drift must stop the sync, not advance the cursor');
        $this->assertSame('no usable rows in page', $result->message);
        $this->assertSame(200, $result->httpCode);
        $this->assertSame([], $result->rows);
        $this->assertNull($result->nextCursor, 'the cursor is never handed back on invalid');
        $this->assertSame(10, $result->creditsRemaining, 'the billed page still reports the balance');
        $this->assertCount(1, $this->logs);
    }

    public function testErrorEnvelopeOnA2xxSurfacesTheProviderMessage(): void
    {
        $body = self::json(['error' => ['code' => 'schema', 'message' => 'Envelope changed']]);
        $result = $this->client([self::response(200, $body)])->listMedia('movie', null, null, self::IDEM);

        $this->assertSame(WokeometerPageResult::INVALID, $result->outcome);
        $this->assertSame('Envelope changed', $result->message);
    }

    public function testOkPageDoesNotLog(): void
    {
        $this->client([self::response(200, self::json(['data' => [], 'next_cursor' => null]))])
            ->listMedia('movie', null, null, self::IDEM);

        $this->assertSame([], $this->logs);
    }

    // ------------------------------------------------------------------
    // Malformed 2xx
    // ------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function malformedBodies(): array
    {
        return [
            'not json'         => ['{"data": [oops'],
            'empty body'       => [''],
            'top-level list'   => ['[1, 2, 3]'],
            'no data key'      => ['{"next_cursor": null}'],
            'data is object'   => ['{"data": {"a": 1}, "next_cursor": null}'],
            'data is string'   => ['{"data": "x", "next_cursor": null}'],
            'data is null'     => ['{"data": null, "next_cursor": null}'],
        ];
    }

    #[DataProvider('malformedBodies')]
    public function testMalformed2xxIsInvalid(string $body): void
    {
        $result = $this->client([self::response(200, $body)])->listMedia('movie', null, null, self::IDEM);

        $this->assertSame(WokeometerPageResult::INVALID, $result->outcome);
        $this->assertSame(200, $result->httpCode);
        $this->assertSame([], $result->rows);
    }

    // ------------------------------------------------------------------
    // Status classification
    // ------------------------------------------------------------------

    /** @return array<string, array{0: int, 1: string}> */
    public static function statusOutcomes(): array
    {
        return [
            '400' => [400, WokeometerPageResult::INVALID],
            '401' => [401, WokeometerPageResult::AUTH],
            '402' => [402, WokeometerPageResult::OUT_OF_CREDITS],
            '403' => [403, WokeometerPageResult::FORBIDDEN],
            '404' => [404, WokeometerPageResult::INVALID],
            '409' => [409, WokeometerPageResult::CONFLICT],
            '418' => [418, WokeometerPageResult::INVALID],
            '302' => [302, WokeometerPageResult::INVALID],
            '500' => [500, WokeometerPageResult::TRANSIENT],
            '503' => [503, WokeometerPageResult::TRANSIENT],
        ];
    }

    #[DataProvider('statusOutcomes')]
    public function testStatusClassification(int $code, string $outcome): void
    {
        $body = self::json(['error' => ['code' => 'x', 'message' => "HTTP {$code} said no"]]);
        $result = $this->client([self::response($code, $body)])->listMedia('movie', null, null, self::IDEM);

        $this->assertSame($outcome, $result->outcome);
        $this->assertSame($code, $result->httpCode);
        $this->assertSame([], $result->rows);
        $this->assertNull($result->retryAfter);
        $this->assertSame("HTTP {$code} said no", $result->message);
    }

    public function testAuthErrorObjectMessageIsSurfaced(): void
    {
        $body = self::json(['error' => ['code' => 'invalid_api_key', 'message' => 'Use an API key in Authorization: Bearer or x-api-key.']]);
        $result = $this->client([self::response(401, $body)])->listMedia('movie', null, null, self::IDEM);

        $this->assertSame(WokeometerPageResult::AUTH, $result->outcome);
        $this->assertSame('Use an API key in Authorization: Bearer or x-api-key.', $result->message);
    }

    public function testErrorWithoutParseableBodyStillHasAMessage(): void
    {
        $result = $this->client([self::response(503, '<html>Service Unavailable</html>')])
            ->listMedia('movie', null, null, self::IDEM);

        $this->assertSame(WokeometerPageResult::TRANSIENT, $result->outcome);
        $this->assertNotNull($result->message);
        $this->assertStringNotContainsString('<html>', (string) $result->message, 'the raw body is never surfaced');
    }

    // ------------------------------------------------------------------
    // 429
    // ------------------------------------------------------------------

    public function testRateLimitedPrefersTheRetryAfterHeader(): void
    {
        $body = self::json(['error' => ['code' => 'rate_limited', 'message' => 'slow down'], 'retry_after' => 60]);
        $result = $this->client([self::response(429, $body, ['Retry-After' => '30'])])
            ->listMedia('movie', null, null, self::IDEM);

        $this->assertSame(WokeometerPageResult::RATE_LIMITED, $result->outcome);
        $this->assertSame(30, $result->retryAfter);
        $this->assertSame('slow down', $result->message);
    }

    public function testRateLimitedEdgeStringBodyUsesRetryAfterField(): void
    {
        $body = self::json(['error' => 'Too many requests. Retry in 45 seconds.', 'code' => 'rate_limited', 'retry_after' => 45]);
        $result = $this->client([self::response(429, $body)])->listMedia('movie', null, null, self::IDEM);

        $this->assertSame(WokeometerPageResult::RATE_LIMITED, $result->outcome);
        $this->assertSame(45, $result->retryAfter);
        $this->assertSame('Too many requests. Retry in 45 seconds.', $result->message);
    }

    public function testRateLimitedEdgeBodyWithSixtySeconds(): void
    {
        $body = self::json(['error' => 'Too many requests. Retry in 60 seconds.', 'code' => 'rate_limited', 'retry_after' => 60]);
        $result = $this->client([self::response(429, $body)])->listMedia('movie', null, null, self::IDEM);

        $this->assertSame(60, $result->retryAfter);
    }

    /** @return array<string, array{0: array<string, string>, 1: string, 2: int}> */
    public static function retryAfterCases(): array
    {
        return [
            'header 0, no body value → 60'   => [['Retry-After' => '0'], '{}', 60],
            'header 0 falls through to body' => [['Retry-After' => '0'], '{"retry_after": 20}', 20],
            'negative header → 60'           => [['Retry-After' => '-5'], '{}', 60],
            'header 7200 clamped to 3600'    => [['Retry-After' => '7200'], '{}', 3600],
            'body 7200 clamped to 3600'      => [[], '{"retry_after": 7200}', 3600],
            'decimal body floored'           => [[], '{"retry_after": 12.7}', 12],
            'decimal header floored'         => [['Retry-After' => '2.5'], '{}', 2],
            'sub-second body → 60'           => [[], '{"retry_after": 0.5}', 60],
            'body 0 → 60'                    => [[], '{"retry_after": 0}', 60],
            'string body digits'             => [[], '{"retry_after": "15"}', 15],
            'header 1 kept (lower bound)'    => [['Retry-After' => '1'], '{}', 1],
        ];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('retryAfterCases')]
    public function testRetryAfterIsPositiveAndClamped(array $headers, string $body, int $expected): void
    {
        $result = $this->client([self::response(429, $body, $headers)])->listMedia('movie', null, null, self::IDEM);

        $this->assertSame(WokeometerPageResult::RATE_LIMITED, $result->outcome);
        $this->assertSame($expected, $result->retryAfter);
    }

    public function testRateLimitedWithNeitherDefaultsToSixty(): void
    {
        $result = $this->client([self::response(429, 'Too Many Requests', ['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT'])])
            ->listMedia('movie', null, null, self::IDEM);

        $this->assertSame(WokeometerPageResult::RATE_LIMITED, $result->outcome);
        $this->assertSame(60, $result->retryAfter);
    }

    // ------------------------------------------------------------------
    // Transport
    // ------------------------------------------------------------------

    public function testCurlErrorIsTransport(): void
    {
        $result = $this->client([['body' => false, 'code' => 0, 'error' => 'Could not resolve host: wokeometer.app', 'headerSize' => 0]])
            ->listMedia('movie', null, null, self::IDEM);

        $this->assertSame(WokeometerPageResult::TRANSPORT, $result->outcome);
        $this->assertSame(0, $result->httpCode);
        $this->assertSame('Could not resolve host: wokeometer.app', $result->message);
    }

    public function testCodeZeroWithoutErrorIsTransport(): void
    {
        $result = $this->client([['body' => '', 'code' => 0, 'error' => '', 'headerSize' => 0]])
            ->listMedia('movie', null, null, self::IDEM);

        $this->assertSame(WokeometerPageResult::TRANSPORT, $result->outcome);
        $this->assertNotNull($result->message);
    }

    public function testTimeoutAfterStatusLineIsTransport(): void
    {
        // A timeout mid-body can still leave a 200 code: the curl error wins
        // (the page may have been billed — the caller retries with the SAME key).
        $result = $this->client([['body' => "HTTP/2 200\r\n\r\n{\"da", 'code' => 200, 'error' => 'Operation timed out', 'headerSize' => 14]])
            ->listMedia('movie', null, null, self::IDEM);

        $this->assertSame(WokeometerPageResult::TRANSPORT, $result->outcome);
    }

    // ------------------------------------------------------------------
    // Message sanitization + logging
    // ------------------------------------------------------------------

    public function testMessageIsSanitizedRedactedAndTruncated(): void
    {
        $raw = "Bad key " . self::KEY . "\x00\x07\n\r\t end " . str_repeat('x', 400);
        $result = $this->client([self::response(400, self::json(['error' => ['code' => 'bad', 'message' => $raw]]))])
            ->listMedia('movie', null, null, self::IDEM);

        $message = (string) $result->message;
        $this->assertStringNotContainsString(self::KEY, $message);
        $this->assertStringContainsString('[redacted]', $message);
        $this->assertSame(0, preg_match('/[\x00-\x1F\x7F]/', $message), 'control characters stripped');
        $this->assertLessThanOrEqual(255, mb_strlen($message));
    }

    public function testPartialKeyEchoesAreRedacted(): void
    {
        // A provider echoing a truncated key (or someone else's key) is still redacted.
        $raw = 'key wok_' . substr(self::KEY, 4, 6) . '… rejected; also WOK_ABCDEF and wok_' . strtoupper(substr(self::KEY, 10, 12));
        $message = (string) $this->client([self::response(401, self::json(['error' => ['code' => 'k', 'message' => $raw]]))])
            ->listMedia('movie', null, null, self::IDEM)->message;

        $this->assertStringNotContainsString(substr(self::KEY, 4, 6), $message);
        $this->assertStringNotContainsString(strtoupper(substr(self::KEY, 10, 12)), $message);
        $this->assertStringContainsString('key [redacted]', $message);
        $this->assertSame(0, preg_match('/wok_[0-9a-f]{4,}/i', $message));
        $this->assertNoKeyMaterialLogged();
    }

    public function testANewlineSplitKeyIsRedactedWhole(): void
    {
        // Redaction runs BEFORE the control-character pass: once "\n" became
        // a space the two halves would no longer look like one key.
        $half  = intdiv(strlen(self::KEY), 2);
        $split = substr(self::KEY, 0, $half) . "\n" . substr(self::KEY, $half);
        $crlf  = substr(self::KEY, 0, 12) . "\r\n" . substr(self::KEY, 12);
        $message = (string) $this->client([self::response(400, self::json(['error' => ['code' => 'k', 'message' => "bad $split / $crlf end"]]))])
            ->listMedia('movie', null, null, self::IDEM)->message;

        $this->assertSame('bad [redacted] / [redacted] end', $message);
        $this->assertStringNotContainsString(substr(self::KEY, $half), $message, 'the second half is not left behind');
        $this->assertNoKeyMaterialLogged();
    }

    public function testNonScalarErrorMessageIsIgnored(): void
    {
        $result = $this->client([self::response(400, self::json(['error' => ['code' => 'bad', 'message' => ['nested']]]))])
            ->listMedia('movie', null, null, self::IDEM);

        $this->assertSame(WokeometerPageResult::INVALID, $result->outcome);
        $this->assertIsString($result->message);
        $this->assertStringNotContainsString('nested', (string) $result->message);
    }

    public function testFailureLogsOneWarningWithoutKeyMaterial(): void
    {
        $body = self::json(['error' => ['code' => 'invalid_api_key', 'message' => 'Key ' . self::KEY . ' was revoked']]);
        $this->client([self::response(401, $body)])->listMedia('movie', 'cur', self::SEP_2026, self::IDEM);

        $this->assertCount(1, $this->logs);
        $record = $this->logs[0];
        $this->assertSame('warning', $record['level']);
        $this->assertSame('Wokeometer request failed', $record['message']);
        $this->assertSame(['path', 'type', 'code', 'outcome', 'message'], array_keys($record['context']));
        $this->assertSame('/media', $record['context']['path']);
        $this->assertSame('movie', $record['context']['type']);
        $this->assertSame(401, $record['context']['code']);
        $this->assertSame(WokeometerPageResult::AUTH, $record['context']['outcome']);

        $this->assertNoKeyMaterialLogged();
    }

    /** @return array<string, array{0: array{body: string|false, code: int, error: string, headerSize: int}}> */
    public static function failingResponses(): array
    {
        $echo = 'echo ' . self::KEY;

        return [
            '401'       => [self::response(401, (string) json_encode(['error' => ['code' => 'k', 'message' => $echo]]))],
            '429 edge'  => [self::response(429, (string) json_encode(['error' => $echo, 'retry_after' => 60]))],
            '503'       => [self::response(503, (string) json_encode(['error' => ['code' => 'k', 'message' => $echo]]))],
            'transport' => [['body' => false, 'code' => 0, 'error' => $echo, 'headerSize' => 0]],
            'bad json'  => [self::response(200, '{"data":' . $echo)],
        ];
    }

    /** @param array{body: string|false, code: int, error: string, headerSize: int} $response */
    #[DataProvider('failingResponses')]
    public function testNoFailureLeaksTheKeyIntoLogsOrResult(array $response): void
    {
        $result = $this->client([$response])->listMedia('tv', 'cur', self::SEP_2026, self::IDEM);

        $this->assertNotSame(WokeometerPageResult::OK, $result->outcome);
        $this->assertCount(1, $this->logs);
        $this->assertNoKeyMaterialLogged();
        $this->assertStringNotContainsString(self::KEY, (string) $result->message);
        $this->assertStringNotContainsString(self::KEY, (string) json_encode($result));
    }

    public function testUnconfiguredLogsWithoutKeyMaterial(): void
    {
        $this->values = ['wokeometer_api_key' => self::KEY, 'wokeometer_enabled' => '0'];
        $this->client()->listMedia('movie', null, null, self::IDEM);

        $this->assertCount(1, $this->logs);
        $this->assertSame(WokeometerPageResult::UNCONFIGURED, $this->logs[0]['context']['outcome']);
        $this->assertNoKeyMaterialLogged();
    }

    public function testRedactsBothTheSentKeyAndTheCurrentlyStoredKey(): void
    {
        $newKey = 'wok_' . str_repeat('fe', 32);
        $client = $this->client([self::response(401, self::json(['error' => [
            'code'    => 'k',
            'message' => 'sent ' . self::KEY . ' stored ' . $newKey . ' hex ' . substr(self::KEY, 4),
        ]]))]);

        $this->assertTrue($client->ready()); // memoizes the OLD key — the one that will be sent
        $this->values['wokeometer_api_key'] = $newKey; // admin saves a new key mid-request (no reset yet)

        $result = $client->listMedia('movie', null, null, self::IDEM);

        $this->assertSame(['Authorization: Bearer ' . self::KEY], array_slice($this->sentHeaders[0], 0, 1), 'the memoized key is what was sent');
        $message = (string) $result->message;
        $this->assertStringNotContainsString(self::KEY, $message);
        $this->assertStringNotContainsString(substr(self::KEY, 4), $message);
        $this->assertStringNotContainsString($newKey, $message);
        $this->assertStringNotContainsString(substr($newKey, 4), $message);
        $this->assertSame('sent [redacted] stored [redacted] hex [redacted]', $message);

        $dump = (string) json_encode($this->logs);
        $this->assertStringNotContainsString(substr(self::KEY, 4), $dump);
        $this->assertStringNotContainsString(substr($newKey, 4), $dump);
    }

    public function testAFailureReadsTheStoredKeyOnce(): void
    {
        $client = $this->client([self::response(503, self::json(['error' => ['code' => 'x', 'message' => 'down']]))]);
        $client->ready(); // load the memo first so only the failure path is counted
        $this->keyReads = 0;

        $client->listMedia('movie', null, null, self::IDEM);

        $this->assertSame(1, $this->keyReads, 'secrets are gathered once per failure, not once per sanitized string');
    }

    public function testNullLoggerIsAccepted(): void
    {
        $result = $this->client([self::response(401, '{}')], new NullLogger())->listMedia('movie', null, null, self::IDEM);
        $this->assertSame(WokeometerPageResult::AUTH, $result->outcome);
    }

    private function assertNoKeyMaterialLogged(): void
    {
        $dump = (string) json_encode($this->logs);
        $this->assertStringNotContainsString(self::KEY, $dump);
        // Not even the hex body of the key (e.g. a "Bearer …" fragment).
        $this->assertStringNotContainsString(substr(self::KEY, 4), $dump);
        $this->assertStringNotContainsString('Bearer', $dump);
    }
}
