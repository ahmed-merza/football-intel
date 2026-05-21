<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Agents\MatchShotEventsExtractor;
use App\Models\MatchReport;
use App\Models\PendingExtraction;
use App\Services\Ai\AgentRouter;
use App\Services\Ai\CallbackPendingException;
use App\Services\Ai\TextPreprocessor;
use App\Services\Match\MatchShotEventsApplier;
use App\Services\Match\MatchShotEventsTextPreprocessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase-2 extractor job for the Shot Details section. Mirrors
 * {@see ExtractMatchReportJob}'s shape — same async-callback safety net,
 * same logging behaviour — but applies to {@see MatchShotEvent} rows
 * instead of the main match_reports / match_performances tables.
 *
 * Failure is NOT terminal for the match: the Phase-1 pipeline (status,
 * resolution UI, applied state) is unaffected if this job fails. The
 * worst case is no shot events for the match. Admin can manually retry
 * later.
 */
class ExtractMatchShotEventsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $matchReportId)
    {
        $this->onQueue('llm');
    }

    /**
     * Belt-and-braces for worker-side failures. Unlike the main
     * extractor, this job's failure doesn't propagate to a "match
     * report failed" UI state — Phase 2 is best-effort enrichment, not
     * a blocker. We just log so the admin can re-trigger later.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('ExtractMatchShotEventsJob failed (worker hook)', [
            'match_report_id' => $this->matchReportId,
            'exception' => $exception->getMessage(),
        ]);
    }

    public function handle(
        MatchShotEventsApplier $applier,
        TextPreprocessor $textPreprocessor,
        MatchShotEventsTextPreprocessor $sectionPreprocessor,
        AgentRouter $router,
    ): void {
        /** @var MatchReport|null $report */
        $report = MatchReport::with('attachment')->find($this->matchReportId);
        if ($report === null) {
            return;
        }

        $text = $report->attachment?->extracted_text;
        if ($text === null || $text === '') {
            return;
        }

        // Two-stage trim, same as the main extractor: section-specific
        // slicer first, then generic Arabic/noise stripper.
        $text = $sectionPreprocessor->trim($text);
        $text = $textPreprocessor->forExtractor($text);

        try {
            /** @var array<string, mixed> $payload */
            $payload = $router->send(new MatchShotEventsExtractor, $text, [
                'kind' => PendingExtraction::KIND_MATCH_SHOT_EVENTS,
                'match_report_id' => $report->id,
            ]);
        } catch (CallbackPendingException $e) {
            // n8n took the request, proxy 504'd, callback will fire
            // later — leave nothing on the match_report (its Phase-1
            // state doesn't track Phase-2 progress).
            Log::info('Shot-events extractor sync timed out, awaiting callback', [
                'match_report_id' => $report->id,
                'correlation_id' => $e->correlationId,
            ]);

            return;
        }

        $applier->apply($report, $payload);
    }
}
