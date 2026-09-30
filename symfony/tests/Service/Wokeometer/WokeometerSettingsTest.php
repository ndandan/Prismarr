<?php

namespace App\Tests\Service\Wokeometer;

use App\Service\ConfigService;
use App\Service\Wokeometer\WokeometerSettings;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class WokeometerSettingsTest extends TestCase
{
    private const KEY = 'wok_0123456789abcdef';

    /** @param array<string, string> $values */
    private function settings(array $values): WokeometerSettings
    {
        $c = $this->createMock(ConfigService::class);
        // Mirror ConfigService::get(): '' reads back as null.
        $c->method('get')->willReturnCallback(fn(string $k) => ($values[$k] ?? '') !== '' ? $values[$k] : null);
        return new WokeometerSettings($c);
    }

    public function testEnabledByDefaultWhenKeyPresent(): void
    {
        $s = $this->settings(['wokeometer_api_key' => self::KEY]);
        $this->assertTrue($s->isEnabled());
        $this->assertSame(self::KEY, $s->apiKey());
    }

    public function testExplicitZeroDisables(): void
    {
        $s = $this->settings(['wokeometer_api_key' => self::KEY, 'wokeometer_enabled' => '0']);
        $this->assertFalse($s->isEnabled());
        $this->assertSame(self::KEY, $s->apiKey(), 'disabling keeps the key');
    }

    public function testOtherEnabledValuesKeepItOn(): void
    {
        $this->assertTrue($this->settings(['wokeometer_api_key' => self::KEY, 'wokeometer_enabled' => '1'])->isEnabled());
    }

    public function testNoKeyMeansDisabled(): void
    {
        $this->assertFalse($this->settings([])->isEnabled());
        $this->assertNull($this->settings([])->apiKey());
        $this->assertFalse($this->settings(['wokeometer_enabled' => '1'])->isEnabled());
        $this->assertNull($this->settings(['wokeometer_api_key' => '   '])->apiKey(), 'whitespace-only key is no key');
        $this->assertFalse($this->settings(['wokeometer_api_key' => '   '])->isEnabled());
    }

    public function testApiKeyIsTrimmed(): void
    {
        $this->assertSame(self::KEY, $this->settings(['wokeometer_api_key' => '  ' . self::KEY . "\n"])->apiKey());
    }

    /** @return iterable<string, array{0: string, 1: bool}> */
    public static function keyShapes(): iterable
    {
        yield 'hex body'           => ['wok_0123456789abcdef', true];
        yield 'mixed alnum, long'  => ['wok_AbCdEf0123456789XyZ', true];
        yield '15 chars'           => ['wok_0123456789abcde', false];
        yield 'no prefix'          => ['0123456789abcdef0123', false];
        yield 'wrong prefix case'  => ['WOK_0123456789abcdef', false];
        yield 'dash in body'       => ['wok_0123-456789abcdef', false];
        yield 'space in body'      => ['wok_0123 456789abcdef', false];
        yield 'header injection'   => ["wok_0123456789abcdef\r\nX-Evil: 1", false];
        yield 'trailing newline'   => ["wok_0123456789abcdef\n", false];
        yield 'empty'              => ['', false];
    }

    #[DataProvider('keyShapes')]
    public function testIsValidKey(string $key, bool $valid): void
    {
        $this->assertSame($valid, WokeometerSettings::isValidKey($key));
    }

    public function testAnInvalidStoredKeyCountsAsNoKey(): void
    {
        $s = $this->settings(['wokeometer_api_key' => 'wok_short']);
        $this->assertNull($s->apiKey());
        $this->assertFalse($s->isEnabled());
    }

    public function testRefreshInvalidatesTheConfigMemo(): void
    {
        $c = $this->createMock(ConfigService::class);
        $c->expects($this->once())->method('invalidate');
        (new WokeometerSettings($c))->refresh();
    }

    public function testAutoSyncDefaultsOnAndZeroDisables(): void
    {
        $this->assertTrue($this->settings([])->isAutoSyncEnabled());
        $this->assertTrue($this->settings(['wokeometer_auto_sync' => '1'])->isAutoSyncEnabled());
        $this->assertFalse($this->settings(['wokeometer_auto_sync' => '0'])->isAutoSyncEnabled());
    }

    public function testConstants(): void
    {
        $this->assertSame(30, WokeometerSettings::SYNC_INTERVAL_DAYS);
        $this->assertSame('https://wokeometer.app', WokeometerSettings::PUBLIC_BASE_URL);
    }

    public function testPublicUrlBuildsForValidSlugs(): void
    {
        $s = $this->settings([]);
        $this->assertSame('https://wokeometer.app/media/movie/the-matrix-1999', $s->publicUrl('movie', 'the-matrix-1999'));
        $this->assertSame('https://wokeometer.app/media/tv/Severance', $s->publicUrl('tv', 'Severance'));
        $this->assertSame('https://wokeometer.app/media/movie/7', $s->publicUrl('movie', '7'));
    }

    /** @return iterable<string, array{0: string, 1: ?string}> */
    public static function invalidUrlProvider(): iterable
    {
        yield 'null slug'          => ['movie', null];
        yield 'empty slug'         => ['movie', ''];
        yield 'leading dash'       => ['movie', '-matrix'];
        yield 'slash traversal'    => ['movie', '../admin'];
        yield 'query injection'    => ['movie', 'a?b=c'];
        yield 'space'              => ['movie', 'the matrix'];
        yield 'unicode'            => ['movie', 'amélie'];
        yield 'trailing newline'   => ['movie', "matrix\n"];
        yield 'javascript'         => ['movie', 'javascript:alert(1)'];
        yield 'library vocabulary' => ['series', 'severance'];
        yield 'unknown type'       => ['episode', 'x'];
    }

    #[DataProvider('invalidUrlProvider')]
    public function testPublicUrlNullForInvalidInput(string $type, ?string $slug): void
    {
        $this->assertNull($this->settings([])->publicUrl($type, $slug));
    }
}
