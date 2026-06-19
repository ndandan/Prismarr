<?php

namespace App\Tests\Twig;

use App\Twig\RelativeDateExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class RelativeDateExtensionTest extends TestCase
{
    public function testBucketBoundaries(): void
    {
        self::assertSame(['dashboard.relative.today', []],            RelativeDateExtension::bucket(0));
        self::assertSame(['dashboard.relative.yesterday', []],        RelativeDateExtension::bucket(1));
        self::assertSame(['dashboard.relative.days_ago', ['count' => 5]],   RelativeDateExtension::bucket(5));
        self::assertSame(['dashboard.relative.weeks_ago', ['count' => 2]],  RelativeDateExtension::bucket(14));
        self::assertSame(['dashboard.relative.months_ago', ['count' => 2]], RelativeDateExtension::bucket(60));
        self::assertSame(['dashboard.relative.years_ago', ['count' => 1]],  RelativeDateExtension::bucket(400));

        // Fence-posts around each boundary catch off-by-one in the comparisons.
        self::assertSame('dashboard.relative.days_ago',   RelativeDateExtension::bucket(6)[0]);
        self::assertSame('dashboard.relative.weeks_ago',  RelativeDateExtension::bucket(7)[0]);
        self::assertSame('dashboard.relative.weeks_ago',  RelativeDateExtension::bucket(29)[0]);
        self::assertSame('dashboard.relative.months_ago', RelativeDateExtension::bucket(30)[0]);
        self::assertSame('dashboard.relative.months_ago', RelativeDateExtension::bucket(364)[0]);
        self::assertSame('dashboard.relative.years_ago',  RelativeDateExtension::bucket(365)[0]);
    }

    public function testFormatReturnsNullForNonPositiveEpoch(): void
    {
        $ext = new RelativeDateExtension($this->createMock(TranslatorInterface::class));
        self::assertNull($ext->format(0));
        self::assertNull($ext->format(-1));
        self::assertNull($ext->format(null));
    }
}
