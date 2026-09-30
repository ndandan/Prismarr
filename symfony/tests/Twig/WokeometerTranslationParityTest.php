<?php

namespace App\Tests\Twig;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guard: every Wokeometer translation key exists in BOTH catalogs (EN is the
 * source of truth, FR must mirror it) and no English value is empty. A key
 * missing in one locale renders as the raw key path in the UI.
 */
class WokeometerTranslationParityTest extends TestCase
{
    private const TRANSLATIONS = __DIR__ . '/../../translations/messages+intl-icu.%s.yaml';

    public function testWokeometerKeySetsAreIdenticalInEnglishAndFrench(): void
    {
        $en = $this->wokeometerKeys('en');
        $fr = $this->wokeometerKeys('fr');

        self::assertNotEmpty($en, 'EN catalog must define Wokeometer keys');
        self::assertSame([], array_values(array_diff($en, $fr)), 'keys present in EN but missing in FR');
        self::assertSame([], array_values(array_diff($fr, $en)), 'keys present in FR but missing in EN');
    }

    public function testEveryNamespaceIsPopulated(): void
    {
        $keys = $this->wokeometerKeys('en');
        foreach (['wokeometer.', 'admin.wokeometer.', 'admin.services.group.enrichment'] as $prefix) {
            $found = array_filter($keys, static fn (string $k): bool => str_starts_with($k, $prefix));
            self::assertNotEmpty($found, "no EN keys under $prefix");
        }
    }

    public function testNoEnglishValueIsEmpty(): void
    {
        foreach (['en', 'fr'] as $locale) {
            foreach ($this->flatten($this->catalog($locale)) as $key => $value) {
                if (!$this->isWokeometerKey($key)) {
                    continue;
                }
                self::assertNotSame('', trim((string) $value), "$locale value of $key is empty");
            }
        }
    }

    /** @return list<string> */
    private function wokeometerKeys(string $locale): array
    {
        $keys = [];
        foreach (array_keys($this->flatten($this->catalog($locale))) as $key) {
            if ($this->isWokeometerKey((string) $key)) {
                $keys[] = (string) $key;
            }
        }
        sort($keys);

        return $keys;
    }

    private function isWokeometerKey(string $key): bool
    {
        return str_starts_with($key, 'wokeometer.')
            || str_starts_with($key, 'admin.wokeometer.')
            || $key === 'admin.services.group.enrichment';
    }

    /** @return array<mixed> */
    private function catalog(string $locale): array
    {
        $parsed = Yaml::parseFile(sprintf(self::TRANSLATIONS, $locale));
        self::assertIsArray($parsed);

        return $parsed;
    }

    /**
     * @param array<mixed> $tree
     * @return array<string, mixed>
     */
    private function flatten(array $tree, string $prefix = ''): array
    {
        $flat = [];
        foreach ($tree as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                $flat += $this->flatten($value, $path);
            } else {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }
}
