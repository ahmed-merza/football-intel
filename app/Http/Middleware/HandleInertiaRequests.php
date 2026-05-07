<?php

namespace App\Http\Middleware;

use App\Models\PlayerRecord;
use App\Models\Submission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // Deferred so the count only fires on a full visit + shows a
            // live badge on the sidebar Review item. Cached for 1 hour;
            // the review confirm / upload paths invalidate it below.
            'reviewCount' => fn (): int => $request->user() === null
                ? 0
                : Cache::remember(
                    'review.count.v1',
                    3600,
                    fn (): int => PlayerRecord::query()
                        ->where('reviewed', false)
                        ->whereHas('submission', fn ($q) => $q->where('status', Submission::STATUS_NEEDS_REVIEW))
                        ->count(),
                ),
        ];
    }
}
