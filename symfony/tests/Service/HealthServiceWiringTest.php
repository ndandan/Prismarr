<?php

namespace App\Tests\Service;

use App\Service\HealthService;
use App\Service\Http\ConcurrentCurl;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The concurrent cold sweep only runs when the container hands HealthService
 * its ConcurrentCurl runner (a nullable, optional argument). Every other
 * health test builds the service by hand, so without this an exclude or a
 * namespace move would silently fall back to the serial sweep.
 */
class HealthServiceWiringTest extends KernelTestCase
{
    public function testTheContainerWiresTheConcurrentRunner(): void
    {
        self::bootKernel();
        $health = static::getContainer()->get(HealthService::class);
        $this->assertInstanceOf(HealthService::class, $health);

        $prop = new \ReflectionProperty(HealthService::class, 'concurrent');
        $this->assertInstanceOf(ConcurrentCurl::class, $prop->getValue($health));
    }
}
