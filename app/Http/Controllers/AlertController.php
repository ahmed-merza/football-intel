<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Alert;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin queue for medical alerts the rule-based flagger + Nutritionist
 * Assistant raise on player records (low Vit D, ferritin, abnormal
 * weight change, etc.). One row per finding; acknowledge collapses
 * the row out of the open queue with a who/when stamp for audit.
 */
class AlertController extends Controller
{
    public function index(Request $request): Response
    {
        $filter = (string) $request->query('filter', 'open');

        $alerts = Alert::query()
            ->with(['player:id,full_name,name_ar,photo_path', 'acknowledger:id,name'])
            ->when(
                $filter === 'open',
                fn ($q) => $q->whereNull('acknowledged_at'),
            )
            ->when(
                $filter === 'acknowledged',
                fn ($q) => $q->whereNotNull('acknowledged_at'),
            )
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warn' THEN 1 ELSE 2 END")
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return Inertia::render('alerts/index', [
            'alerts' => $alerts->map(fn (Alert $a): array => [
                'id' => $a->id,
                'severity' => $a->severity,
                'kind' => $a->kind,
                'message' => $a->message,
                'created_at' => $a->created_at?->toIso8601String(),
                'acknowledged_at' => $a->acknowledged_at?->toIso8601String(),
                'acknowledged_by' => $a->acknowledger?->name,
                'player' => [
                    'id' => $a->player->id,
                    'full_name' => $a->player->full_name,
                    'name_ar' => $a->player->name_ar,
                    'photo_url' => $a->player->photo_path !== null
                        ? Storage::disk('public')->url($a->player->photo_path)
                        : null,
                ],
            ])->all(),
            'counts' => [
                'open' => Alert::open()->count(),
                'acknowledged' => Alert::whereNotNull('acknowledged_at')->count(),
            ],
            'filter' => $filter,
        ]);
    }

    public function acknowledge(Request $request, Alert $alert): RedirectResponse
    {
        if ($alert->acknowledged_at !== null) {
            return back();
        }

        $alert->update([
            'acknowledged_at' => now(),
            'acknowledged_by' => $request->user()?->id,
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Alert acknowledged.'),
        ]);

        return back();
    }

    /**
     * Undo an acknowledgement — sends the alert back to the open queue.
     * Idempotent: a second call on an already-open alert is a no-op so
     * a stale tab can't trip the flow.
     */
    public function unacknowledge(Alert $alert): RedirectResponse
    {
        if ($alert->acknowledged_at === null) {
            return back();
        }

        $alert->update([
            'acknowledged_at' => null,
            'acknowledged_by' => null,
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Alert reopened.'),
        ]);

        return back();
    }
}
