<?php

namespace App\MessageHandler;

use App\Message\RefreshCacheKey;
use App\Service\Cache\CacheRefresherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RefreshCacheKeyHandler
{
    /** @param iterable<CacheRefresherInterface> $refreshers */
    public function __construct(
        #[AutowireIterator('app.cache_refresher')]
        private readonly iterable $refreshers,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(RefreshCacheKey $message): void
    {
        foreach ($this->refreshers as $refresher) {
            if ($refresher->supports($message->key)) {
                $refresher->refresh($message->key);

                return;
            }
        }

        // Deliberately NOT thrown: a throw costs three retries and then a
        // row in the `failed` transport nobody monitors. A warning is the
        // right amount of noise for a key whose owner was removed.
        $this->logger->warning('RefreshCacheKey: no refresher claims this key', ['key' => $message->key]);
    }
}
