<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Agents\MatchReportExtractor;
use App\Models\MatchReport;
use App\Models\PendingExtraction;
use App\Services\Ai\AgentErrorMessage;
use App\Services\Ai\AgentRouter;
use App\Services\Ai\CallbackPendingException;
use App\Services\Ai\TextPreprocessor;
use App\Services\Match\MatchReportApplier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Match-report ingestion job — mirrors {@see ExtractStructuredDataJob}
 * for the AGCFF/Wyscout PDF flow. Reads the attachment's extracted_text,
 * runs the {@see MatchReportExtractor} through {@see AgentRouter}, and
 * (on success) hands the payload to {@see MatchReportApplier::applyExtraction}.
 *
 * Async insurance: every n8n call goes through the gateway with a
 * callback_url + correlation_id. If nginx times out before n8n responds,
 * the gateway throws {@see CallbackPendingException} — we flip the
 * match_report to `awaiting_callback` and exit cleanly. The webhook
 * controller closes the loop when n8n eventually POSTs the result. No
 * Horizon retry on 504 (would redo the work pointlessly), but the
 * failed() hook still fires for other exceptions so genuine errors still
 * retry as normal.
 */
class ExtractMatchReportJob implements ShouldQueue
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
     * Belt-and-braces for failures the try/catch inside handle() can't
     * see — timeout kills (the worker signals from outside the PHP call
     * stack), OOM, worker restarts. Flips status to `failed` so the UI
     * surfaces a retry button instead of leaving the row silently stuck.
     */
    public function failed(Throwable $exception): void
    {
        $report = MatchReport::find($this->matchReportId);
        if ($report === null) {
            return;
        }

        Log::error('ExtractMatchReportJob failed (worker hook)', [
            'match_report_id' => $report->id,
            'exception' => $exception->getMessage(),
        ]);

        $report->update([
            'status' => MatchReport::STATUS_FAILED,
            'extraction_error' => AgentErrorMessage::humanise($exception),
        ]);
    }

    public function handle(
        MatchReportApplier $applier,
        TextPreprocessor $preprocessor,
        AgentRouter $router,
    ): void {
        /** @var MatchReport|null $report */
        $report = MatchReport::with(['attachment'])->find($this->matchReportId);
        if ($report === null) {
            return;
        }

        $text = $report->attachment?->extracted_text;
        if ($text === null || $text === '') {
            // Text extraction step (ExtractTextFromAttachmentJob) hasn't
            // produced anything yet — leave status alone, the upload
            // pipeline retries.
            return;
        }

        // Match reports are mostly Latin tables + numbers; default extractor
        // preprocessing applies. Don't truncate (would risk dropping the
        // second team's player stats which appear later in the PDF).
        $text = $preprocessor->forExtractor($text);

        $report->update(['status' => MatchReport::STATUS_EXTRACTING]);

        try {
            /** @var array<string, mixed> $payload */
            $payload = $router->send(new MatchReportExtractor, $text, [
                'kind' => PendingExtraction::KIND_MATCH_REPORT,
                'match_report_id' => $report->id,
            ]);
        } catch (CallbackPendingException $e) {
            // Sync HTTP path timed out. n8n is still running upstream;
            // the callback POST will close the loop via N8nWebhookController.
            Log::info('Match-report extractor sync timed out, awaiting callback', [
                'match_report_id' => $report->id,
                'correlation_id' => $e->correlationId,
            ]);

            $report->update([
                'status' => MatchReport::STATUS_AWAITING_CALLBACK,
                'extraction_error' => null,
            ]);

            return;
        }

        $applier->applyExtraction($report, $payload);
    }
}
