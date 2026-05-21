<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Agents\MatchGoalkeeperEventsExtractor;
use App\Models\MatchReport;
use App\Models\PendingExtraction;
use App\Services\Ai\AgentRouter;
use App\Services\Ai\CallbackPendingException;
use App\Services\Ai\TextPreprocessor;
use App\Services\Match\MatchGoalkeeperEventsApplier;
use App\Services\Match\MatchGoalkeeperTextPreprocessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase-2 extractor job for the Goalkeeper section. Mirrors
 * {@see ExtractMatchShotEventsJob} — same async-callback safety net,
 * same best-effort failure semantics. Failures don't affect the main
 * match-report status.
 */
class ExtractMatchGoalkeeperEventsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $matchReportId)
    {
        $this->onQueue('llm');
    }

    public function failed(Throwable $exception): void
    {
        Log::error('ExtractMatchGoalkeeperEventsJob failed (worker hook)', [
            'match_report_id' => $this->matchReportId,
            'exception' => $exception->getMessage(),
        ]);
    }

    public function handle(
        MatchGoalkeeperEventsApplier $applier,
        TextPreprocessor $textPreprocessor,
        MatchGoalkeeperTextPreprocessor $sectionPreprocessor,
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

        $text = $sectionPreprocessor->trim($text);
        $text = $textPreprocessor->forExtractor($text);

        try {
            /** @var array<string, mixed> $payload */
            $payload = $router->send(new MatchGoalkeeperEventsExtractor, $text, [
                'kind' => PendingExtraction::KIND_MATCH_GOALKEEPER_EVENTS,
                'match_report_id' => $report->id,
            ]);
        } catch (CallbackPendingException $e) {
            Log::info('GK-events extractor sync timed out, awaiting callback', [
                'match_report_id' => $report->id,
                'correlation_id' => $e->correlationId,
            ]);

            return;
        }

        $applier->apply($report, $payload);
    }
}
