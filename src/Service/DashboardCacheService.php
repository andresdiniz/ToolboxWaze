<?php
declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class DashboardCacheService
{
    public function __construct(private readonly CacheInterface $dashboardCache)
    {
    }

    public function getCached(string $key, callable $callback, int $ttl = 3600): mixed
    {
        return $this->dashboardCache->get($key, function (ItemInterface $item) use ($callback, $ttl) {
            $item->expiresAfter($ttl);
            return $callback();
        });
    }

    public function invalidate(string $key): void
    {
        $this->dashboardCache->delete($key);
    }
}