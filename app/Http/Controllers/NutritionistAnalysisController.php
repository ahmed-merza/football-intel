<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\GenerateNutritionistAnalysisJob;
use App\Models\NutritionistAnalysis;
use App\Models\Player;
use App\Models\RecordCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Triggers a Nutritionist Assistant run for a player. Creates the
 * analysis row in pending state up front so the UI has something to
 * render while the job executes; the worker promotes it to completed
 * or failed.
 */
class NutritionistAnalysisController extends Controller
{
    public function store(Request $request, Player $player): RedirectResponse
    {
        if (! $this->hasRequiredRecords($player)) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Need at least one blood test AND one body-composition record on file before running an analysis.'),
            ]);

            return back();
        }

        $analysis = NutritionistAnalysis::create([
            'player_id' => $player->id,
            'generated_by' => $request->user()?->id,
            'status' => NutritionistAnalysis::STATUS_PENDING,
        ]);

        GenerateNutritionistAnalysisJob::dispatch($analysis->id);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Analysis queued — it will appear here once the assistant finishes.'),
        ]);

        return back();
    }

    /**
     * Re-run a failed analysis in place. Keeps the same row (so previous
     * runs in the history don't get a duplicate) but resets status to
     * pending and clears the error message — the UI immediately stops
     * showing "Analysis failed" and switches to the in-flight state.
     *
     * The job itself also has the failed→pending reset as a
     * belt-and-braces invariant for non-UI retry paths (CLI
     * queue:retry), but doing it here too means the UI flips state
     * instantly on click instead of waiting for the worker to pick up.
     *
     * Refuses non-failed rows — there's nothing to retry on completed
     * (would clobber the result) or pending (job is already running).
     */
    public function retry(NutritionistAnalysis $analysis): RedirectResponse
    {
        if ($analysis->status !== NutritionistAnalysis::STATUS_FAILED) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Only failed analyses can be retried.'),
            ]);

            return back();
        }

        $analysis->update([
            'status' => NutritionistAnalysis::STATUS_PENDING,
            'error' => null,
        ]);

        GenerateNutritionistAnalysisJob::dispatch($analysis->id);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Retry queued — it will appear here once the assistant finishes.'),
        ]);

        return back();
    }

    private function hasRequiredRecords(Player $player): bool
    {
        $hasBlood = $player->records()
            ->whereHas('category', fn ($q) => $q->where('slug', RecordCategory::BLOOD_TEST))
            ->exists();
        $hasInbody = $player->records()
            ->whereHas('category', fn ($q) => $q->where('slug', RecordCategory::INBODY))
            ->exists();

        return $hasBlood && $hasInbody;
    }
}
