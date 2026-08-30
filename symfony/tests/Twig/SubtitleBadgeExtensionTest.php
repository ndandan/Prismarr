<?php

namespace App\Tests\Twig;

use App\Service\Media\BazarrSubtitleIndex;
use App\Twig\SubtitleBadgeExtension;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class SubtitleBadgeExtensionTest extends TestCase
{
    public function testStatusDispatchesToIndexByKind(): void
    {
        $index = $this->createMock(BazarrSubtitleIndex::class);
        $index->method('movieStatus')->willReturn(['state' => 'missing', 'count' => 2]);
        $index->method('seriesStatus')->willReturn(['state' => 'complete', 'count' => 0]);
        $ext = new SubtitleBadgeExtension($index);
        $this->assertSame('missing', $ext->status('movie', 1)['state']);
        $this->assertSame('complete', $ext->status('series', 5)['state']);
    }

    public function testUnknownKindIsHidden(): void
    {
        $index = $this->createMock(BazarrSubtitleIndex::class);
        $ext = new SubtitleBadgeExtension($index);
        $this->assertSame('hidden', $ext->status('bogus', 1)['state']);
    }

    public function testGetFunctionsRegistersSubtitleStatus(): void
    {
        $index = $this->createMock(BazarrSubtitleIndex::class);
        $ext = new SubtitleBadgeExtension($index);
        $names = array_map(static fn ($f) => $f->getName(), $ext->getFunctions());
        $this->assertContains('subtitle_status', $names);
    }
}
