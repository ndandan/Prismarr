<?php

namespace App\Tests\Controller;

use App\Controller\MediaController;
use PHPUnit\Framework\TestCase;

/**
 * globalSearch() used to transliterate 3 fields on ~3,700 rows on EVERY
 * request and re-normalize both sides of every usort comparison. The work
 * moves into the 60 s index; these cases pin the behaviour that must not
 * drift while it moves.
 */
class GlobalSearchNormalizationTest extends TestCase
{
    /** @param list<array<string, mixed>> $rows */
    private function search(array $rows, string $term): array
    {
        $c = (new \ReflectionClass(MediaController::class))->newInstanceWithoutConstructor();
        $m = new \ReflectionMethod(MediaController::class, 'matchAndRank');
        /** @var list<array<string, mixed>> $out */
        $out = $m->invoke($c, $rows, $term);

        return $out;
    }

    /** @param list<string> $titles */
    private function index(array $titles): array
    {
        $c = (new \ReflectionClass(MediaController::class))->newInstanceWithoutConstructor();
        $m = new \ReflectionMethod(MediaController::class, 'normalizeIndexRow');

        return array_map(
            static fn (string $t, int $i) => $m->invoke($c, [
                'id' => $i + 1, 'title' => $t, 'originalTitle' => null, 'sortTitle' => strtolower($t),
                'year' => 2000, 'hasFile' => true, 'poster' => null,
                'instance' => ['slug' => 'radarr-1', 'name' => 'Radarr'],
            ]),
            $titles,
            array_keys($titles),
        );
    }

    public function testAccentAndCaseInsensitiveMatch(): void
    {
        $hits = $this->search($this->index(['Amélie', 'Other']), 'amelie');

        $this->assertCount(1, $hits);
        $this->assertSame('Amélie', $hits[0]['title']);
    }

    public function testStartsWithRanksBeforeAlphabetical(): void
    {
        $hits = $this->search($this->index(['A Matrix Story', 'Matrix']), 'matrix');

        $this->assertSame('Matrix', $hits[0]['title'], 'titles starting with the term come first');
        $this->assertSame('A Matrix Story', $hits[1]['title']);
    }

    public function testAlphabeticalTieBreakUsesTheRawTitle(): void
    {
        $hits = $this->search($this->index(['matrix zulu', 'Matrix Alpha']), 'matrix');

        $this->assertSame('Matrix Alpha', $hits[0]['title']);
    }

    public function testTheOriginalTitleFieldIsSearchable(): void
    {
        $rows = $this->index(['Le Fabuleux Destin']);
        $rows[0]['originalTitle'] = 'Amelie';
        $rows[0]['_n_original']   = 'amelie';

        $this->assertCount(1, $this->search($rows, 'amelie'));
    }

    public function testNormalizedHelperFieldsArePresentOnAnIndexRow(): void
    {
        $row = $this->index(['Amélie'])[0];

        $this->assertSame('amelie', $row['_n_title']);
        $this->assertArrayHasKey('_n_original', $row);
        $this->assertArrayHasKey('_n_sort', $row);
    }

    public function testHelperFieldsAreStrippedBeforeTheResponse(): void
    {
        $c = (new \ReflectionClass(MediaController::class))->newInstanceWithoutConstructor();
        $m = new \ReflectionMethod(MediaController::class, 'stripIndexHelpers');

        $json = json_encode($m->invoke($c, $this->index(['Amélie'])));

        // The client stringifies each whole result into a data-item attribute,
        // so ANY leftover key ships to the DOM.
        $this->assertStringNotContainsString('_n_', (string) $json);
    }
}
