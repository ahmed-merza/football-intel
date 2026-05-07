<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ExtractStructuredDataJob;
use App\Models\PlayerRecord;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Per-record admin actions. Currently just re-extract; future hooks
 * (override extracted fields, hard-delete, etc.) land here too.
 */
class PlayerRecordController extends Controller
{
    /**
     * Re-run structured extraction for one record. Common entry points:
     *   - admin saw `extraction_pending` on the Timeline and wants to
     *     retry without nuking the whole submission
     *   - structured extraction crashed on a timeout — record carries
     *     `extraction_failed=true` from the job's failed() hook
     *   - admin tweaked a prompt / model / config and wants the
     *     existing record re-shaped
     *
     * The job overwrites `extracted` on success, so success
     * automatically clears the `extraction_failed` flag. Metric rows
     * are wiped + re-fanned by the existing fanner.
     */
    public function reExtract(PlayerRecord $record): RedirectResponse
    {
        // Clear stale failure / partial flags eagerly so the UI hides
        // the badge immediately; the job will set them again if the
        // retry also fails or only partially succeeds.
        $extracted = $record->extracted ?? [];
        unset(
            $extracted['extraction_failed'],
            $extracted['extraction_failed_at'],
            $extracted['extraction_failure_reason'],
            $extracted['extraction_partial'],
            $extracted['chunks_completed'],
            $extracted['chunks_total'],
            $extracted['failed_chunk_index'],
        );
        $record->update(['extracted' => $extracted]);

        ExtractStructuredDataJob::dispatch($record->id);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Re-extraction queued.'),
        ]);

        return back();
    }

    /**
     * Admin sign-off: stamps the record reviewed=true with reviewer +
     * timestamp. Idempotent — re-clicking on an already-reviewed record
     * is a no-op (no audit churn). Use unmarkReviewed() to undo.
     */
    public function markReviewed(Request $request, PlayerRecord $record): RedirectResponse
    {
        if ($record->reviewed) {
            return back();
        }

        $record->update([
            'reviewed' => true,
            'reviewed_at' => now(),
            'reviewed_by' => $request->user()?->id,
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Record reviewed.'),
        ]);

        return back();
    }

    /**
     * Undo a sign-off — reviewed=false + clear reviewer + timestamp.
     * Useful when the admin needs to re-inspect a record (e.g. after a
     * re-extraction) or hit the wrong button. Idempotent on
     * already-unreviewed rows.
     */
    public function unmarkReviewed(PlayerRecord $record): RedirectResponse
    {
        if (! $record->reviewed) {
            return back();
        }

        $record->update([
            'reviewed' => false,
            'reviewed_at' => null,
            'reviewed_by' => null,
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Review undone.'),
        ]);

        return back();
    }
}
