<?php

namespace App;

use App\Service\HostResolver;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        $alreadyBooted = $this->booted;
        parent::boot();
        if (!$alreadyBooted) {
            // The SSRF guard is a static call (no DI), so hand it the shared
            // cache once per boot. It lets classic one-process-per-request
            // mode reuse DNS answers across requests (see HostResolver).
            HostResolver::usePool($this->container->get('cache.app'));
        }
    }
}
