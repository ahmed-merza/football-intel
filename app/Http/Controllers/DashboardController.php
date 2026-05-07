<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\NutritionistAnalysis;
use App\Models\Player;
use App\Models\Submission;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Freshness is owned by DashboardCacheObserver — any write to a counted
     * model (Player, Submission, Alert, PlayerRecord) forgets this key
     * immediately. The long TTL is only a safety net for writes that
     * bypass Eloquent events (raw DB::statement, tinker, some seeders)
     * so stale data can't persist forever. One hour is plenty; the
     * observer handles the happy path in milliseconds.
     */
    private const CACHE_KEY = 'dashboard.stats.v1';

    private const CACHE_TTL_SECONDS = 3600;

    public function index(): Response
    {
        $stats = Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->computeStats(),
        );

        return Inertia::render('dashboard', [
            'stats' => $stats,
            'latest_analyses' => $this->latestAnalyses(),
            'recent_activity' => $this->recentActivity(),
        ]);
    }

    /**
     * @return array{
     *     active_players: int,
     *     open_alerts: int,
     *     open_alerts_critical: int,
     *     needs_review: int,
     *     recent_uploads: int,
     * }
     */
    private function computeStats(): array
    {
        return [
            'active_players' => Player::active()->count(),
            'open_alerts' => Alert::open()->count(),
            'open_alerts_critical' => Alert::open()
                ->severity(Alert::SEVERITY_CRITICAL)
                ->count(),
            'needs_review' => Submission::where('status', Submission::STATUS_NEEDS_REVIEW)
                ->count(),
            'recent_uploads' => Submission::where(
                'received_at',
                '>=',
                Carbon::now()->subDays(7),
            )->count(),
        ];
    }

    /**
     * Five most recent completed Nutritionist Assistant runs across the
     * federation, newest first. Skips pending/failed — the dashboard
     * panel is "what does Dr. Fadhel's AI partner have to say lately",
     * not the queue depth.
     *
     * @return list<array<string, mixed>>
     */
    private function latestAnalyses(): array
    {
        $rows = NutritionistAnalysis::query()
            ->with(['player:id,full_name'])
            ->where('status', NutritionistAnalysis::STATUS_COMPLETED)
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        return $rows->map(fn (NutritionistAnalysis $a): array => [
            'id' => $a->id,
            'player_id' => $a->player_id,
            'player_name' => $a->player?->full_name,
            'summary_text' => $a->summary_text,
            'generated_at' => $a->generated_at?->toIso8601String(),
        ])->all();
    }

    /**
     * Mixed activity feed — recent uploads + analyses + critical
     * alerts, all collapsed into a single stream sorted newest first.
     * Lightweight per-row payload so the feed renders without
     * follow-up fetches; click-throughs deep-link to the player.
     *
     * @return list<array<string, mixed>>
     */
    private function recentActivity(): array
    {
        $since = Carbon::now()->subDays(14);

        $uploads = Submission::with(['player:id,full_name'])
            ->where('received_at', '>=', $since)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (Submission $s): array => [
                'kind' => 'upload',
                'at' => $s->received_at->toIso8601String(),
                'player_id' => $s->player_id,
                'player_name' => $s->player?->full_name,
                'message' => 'Submission '.$s->status,
            ]);

        $analyses = NutritionistAnalysis::with(['player:id,full_name'])
            ->where('created_at', '>=', $since)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (NutritionistAnalysis $a): array => [
                'kind' => 'analysis',
                'at' => $a->created_at?->toIso8601String(),
                'player_id' => $a->player_id,
                'player_name' => $a->player?->full_name,
                'message' => $a->status === NutritionistAnalysis::STATUS_COMPLETED
                    ? 'AI analysis ready'
                    : 'AI analysis '.$a->status,
            ]);

        $alerts = Alert::with(['player:id,full_name'])
            ->where('created_at', '>=', $since)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (Alert $a): array => [
                'kind' => 'alert',
                'at' => $a->created_at?->toIso8601String(),
                'player_id' => $a->player_id,
                'player_name' => $a->player?->full_name,
                'severity' => $a->severity,
                'message' => $a->message,
            ]);

        return $uploads->concat($analyses)->concat($alerts)
            ->sortByDesc(fn (array $row): string => (string) ($row['at'] ?? ''))
            ->values()
            ->take(15)
            ->all();
    }
}
