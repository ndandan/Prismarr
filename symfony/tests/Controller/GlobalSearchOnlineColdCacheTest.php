<?php

namespace App\Tests\Controller;

use App\Tests\AbstractWebTestCase;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * `/search/online` lit l'index de recherche mis en cache par `/search`.
 * Sur un cache froid il doit le CONSTRUIRE, pas planter : la version
 * précédente passait un callback dont le fallback était inatteignable
 * (`expiresAfter()` est fluide et renvoie toujours $this), ce qui faisait
 * remonter un ItemInterface jusqu'à array_column() → TypeError → 500.
 */
class GlobalSearchOnlineColdCacheTest extends AbstractWebTestCase
{
    /** MediaController est préfixé par `/medias/{slug}` (instance seedée par AbstractWebTestCase). */
    private const URL = '/medias/radarr-1/search/online';

    public function testSearchOnlineSucceedsOnAColdCache(): void
    {
        $cache = static::getContainer()->get(CacheInterface::class);
        $cache->delete('prismarr_search_movies_v3');
        $cache->delete('prismarr_search_series_v3');

        $this->client->request('GET', self::URL, ['q' => 'matrix']);

        self::assertResponseIsSuccessful();
        self::assertIsArray(json_decode($this->client->getResponse()->getContent(), true));
    }

    /**
     * Le cache ne doit jamais contenir autre chose qu'une liste : si un
     * ItemInterface y est stocké, l'appel SUIVANT le relit tel quel et
     * échoue à son tour pendant les 60s de TTL.
     */
    public function testColdCacheStoresAListNotACacheItem(): void
    {
        $cache = static::getContainer()->get(CacheInterface::class);
        $cache->delete('prismarr_search_movies_v3');

        $this->client->request('GET', self::URL, ['q' => 'matrix']);

        $cached = $cache->get('prismarr_search_movies_v3', fn() => 'MISS');
        self::assertIsArray($cached, 'the search index must be cached as an array');
    }
}
