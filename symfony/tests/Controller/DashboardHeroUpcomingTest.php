<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Review 2026-11-21 L6: the hero's "Upcoming 7d" figure read 0 while every
 * Radarr/Sonarr was down, next to a calendar card saying it failed to load.
 * An outage shows "—", like the library counts beside it.
 */
class DashboardHeroUpcomingTest extends KernelTestCase
{
    private function render(array $vars): string
    {
        self::bootKernel();

        return self::getContainer()->get('twig')->render('dashboard/_hero.html.twig', $vars + [
            'hero_spotlight' => null,
            'stats'          => ['films' => 12, 'series' => 3],
            'upcoming'       => [],
        ]);
    }

    private static function upcomingValue(string $html): string
    {
        // The third stat is the upcoming one.
        preg_match_all('/<div class="hero-stat-value">([^<]*)<\/div>/', $html, $m);

        return trim($m[1][2] ?? '');
    }

    public function testAnOutageShowsADash(): void
    {
        self::assertSame('—', self::upcomingValue($this->render(['upcoming_unreachable' => true])));
    }

    public function testAnEmptyWeekStillShowsZero(): void
    {
        self::assertSame('0', self::upcomingValue($this->render(['upcoming_unreachable' => false])));
        self::assertSame('0', self::upcomingValue($this->render([])), 'older callers without the flag');
    }

    public function testACountIsShown(): void
    {
        self::assertSame('2', self::upcomingValue($this->render(['upcoming' => [['id' => 1], ['id' => 2]]])));
    }
}
