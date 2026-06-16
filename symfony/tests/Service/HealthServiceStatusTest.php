<?php

namespace App\Tests\Service;

use App\Service\HealthService;
use PHPUnit\Framework\TestCase;

class HealthServiceStatusTest extends TestCase
{
    public function testClassifyLatencyBoundaries(): void
    {
        self::assertSame('up',        HealthService::classifyLatency(0));
        self::assertSame('up',        HealthService::classifyLatency(750));
        self::assertSame('slow',      HealthService::classifyLatency(751));
        self::assertSame('slow',      HealthService::classifyLatency(2000));
        self::assertSame('very_slow', HealthService::classifyLatency(2001));
    }
}
