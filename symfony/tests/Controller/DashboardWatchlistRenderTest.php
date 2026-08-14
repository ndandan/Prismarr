<?php

namespace App\Tests\Controller;

use App\Entity\Media\WatchlistItem;
use App\Tests\AbstractWebTestCase;

final class DashboardWatchlistRenderTest extends AbstractWebTestCase
{
    /**
     * Regression: the dashboard/sections/_watchlist.html.twig partial calls
     * ico.icon(...) but only the parent dashboard/index.html.twig imported
     * _icons.html.twig as `ico` — an {% import %} in the caller does not
     * reach an {% include %}'d partial, so this 500'd as soon as the
     * watchlist section actually had a row to render (empty watchlists never
     * hit the branch, which is why the smoke test didn't already catch it).
     */
    public function testDashboardRendersWithANonEmptyWatchlist(): void
    {
        $item = (new WatchlistItem())
            ->setTmdbId(603)
            ->setMediaType('movie')
            ->setTitle('The Matrix')
            ->setPosterPath('/p.jpg')
            ->setVote(8.2)
            ->setYear(1999);
        $em = $this->em();
        $em->persist($item);
        $em->flush();

        $this->client->request('GET', '/tableau-de-bord');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('The Matrix', (string) $this->client->getResponse()->getContent());
    }
}
