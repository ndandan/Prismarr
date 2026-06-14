<?php

namespace App\Tests\Twig;

use App\Twig\RelativeDateExtension;
use PHPUnit\Framework\TestCase;

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
    }
}
