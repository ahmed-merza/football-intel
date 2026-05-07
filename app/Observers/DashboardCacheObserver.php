<?php

declare(strict_types=1);

namespace App\Observers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Forgets dashboard + sidebar-nav counters on any write to the counted
 * models (Player, Submission, Alert, PlayerRecord). The TTLs on the
 * computed values are safety nets — this observer is the primary
 * freshness mechanism.
 */
class DashboardCacheObserver
{
    /** @var list<string> */
    private const CACHE_KEYS = [
        'dashboard.stats.v1',
        'review.count.v1',
    ];

    public function saved(Model $model): void
    {
        $this->flush();
    }

    public function deleted(Model $model): void
    {
        $this->flush();
    }

    public function restored(Model $model): void
    {
        $this->flush();
    }

    private function flush(): void
    {
        foreach (self::CACHE_KEYS as $key) {
            Cache::forget($key);
        }
    }
}
