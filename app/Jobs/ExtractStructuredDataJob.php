<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Agents\BloodTestExtractor;
use App\Ai\Agents\InBodyExtractor;
use App\Ai\Agents\NutritionPlanExtractor;
use App\Models\PendingExtraction;
use App\Models\PlayerRecord;
use App\Models\RecordCategory;
use App\Services\Ai\AgentErrorMessage;
use App\Services\Ai\AgentRouter;
use App\Services\Ai\CallbackPendingException;
use App\Services\Ai\TextPreprocessor;
use App\Services\Medical\ExtractionApplier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Step 3 of the ingestion pipeline: per-category structured extraction.
 *
 * Input: a PlayerRecord ID created by ClassifyAttachmentJob, still
 * carrying the minimal `extracted` payload flagged
 * `needs_structured_extraction=true`.
 *
 * Output: `player_records.extracted` replaced with typed data from the
 * category-specific extractor agent, plus `record_metrics` rows fanned
 * out for chartable numeric values.
 *
 * Handles blood_test + inbody + nutrition_plan; all three share the
 * generic ExtractionApplier and RecordMetricFanner since their
 * structured shapes match. Records in other categories exit early
 * with a flag so the review queue can surface them. Adding another
 * category = new agent + dispatch branch + one entry in
 * RecordMetricFanner's source-key map + one applier method.
 *
 * Async insurance: every n8n call goes through the gateway with a
 * callback_url + correlation_id. If the call returns within nginx's
 * timeout, the result is applied inline. If nginx 504s, the gateway
 * throws CallbackPendingException — we stamp `awaiting_callback` on
 * the record and exit cleanly; n8n's eventual callback POST closes
 * the loop via N8nWebhookController. No Horizon retry on 504 (it'd
 * just redo the work), but the failed() hook still fires for other
 * exceptions so genuine errors retry as before.
 */
class ExtractStructuredDataJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $playerRecordId)
    {
        $this->onQueue('llm');
    }

    /**
     * Belt-and-braces for failures the try/catch inside handle() can't
     * see — timeout kills (the worker signals from outside the PHP call
     * stack), OOM, worker restarts. Flips an `extraction_failed` flag
     * on the record's `extracted` payload so the Timeline can surface a
     * Re-extract button instead of leaving the row silently broken.
     */
    public function failed(Throwable $exception): void
    {
        $record = PlayerRecord::find($this->playerRecordId);
        if ($record === null) {
            return;
        }

        Log::error('ExtractStructuredDataJob failed (worker hook)', [
            'record_id' => $record->id,
            'exception' => $exception->getMessage(),
        ]);

        $extracted = $record->extracted ?? [];
        $extracted['extraction_failed'] = true;
        $extracted['extraction_failed_at'] = now()->toIso8601String();
        $extracted['extraction_failure_reason'] = AgentErrorMessage::humanise($exception);

        $record->update(['extracted' => $extracted]);
    }

    public function handle(
        ExtractionApplier $applier,
        TextPreprocessor $preprocessor,
        AgentRouter $router,
    ): void {
        /** @var PlayerRecord|null $record */
        $record = PlayerRecord::with(['category', 'primaryAttachment'])->find($this->playerRecordId);
        if ($record === null) {
            return;
        }

        $text = $record->primaryAttachment?->extracted_text;
        if ($text === null || $text === '') {
            return;
        }

        // Extractor default: keep Arabic (may carry patient name +
        // metadata) and don't truncate (would risk dropping analytes).
        // Both configurable via AI_STRIP_ARABIC_EXTRACTOR in .env.
        $text = $preprocessor->forExtractor($text);

        match ($record->category->slug) {
            RecordCategory::BLOOD_TEST => $this->runExtractor(
                $record,
                $text,
                new BloodTestExtractor,
                PendingExtraction::KIND_BLOOD_TEST,
                fn (array $payload) => $applier->applyBloodTest($record, $payload),
                $router,
            ),
            RecordCategory::INBODY => $this->runExtractor(
                $record,
                $text,
                new InBodyExtractor,
                PendingExtraction::KIND_INBODY,
                fn (array $payload) => $applier->applyInBody($record, $payload),
                $router,
            ),
            RecordCategory::NUTRITION_PLAN => $this->runExtractor(
                $record,
                $text,
                new NutritionPlanExtractor,
                PendingExtraction::KIND_NUTRITION_PLAN,
                fn (array $payload) => $applier->applyNutritionPlan($record, $payload),
                $router,
            ),
            // Other categories land in their own follow-ups. Until their
            // extractors ship the record keeps its classifier-minimal
            // payload — still visible on the timeline, just not charted.
            default => $this->markPending($record),
        };
    }

    /**
     * Single source for "send the extractor through the router, apply
     * on success, stamp awaiting-callback on 504, fail loudly on
     * everything else". The kind-specific applier closure is the only
     * thing that varies between blood / InBody / nutrition.
     *
     * @param  BloodTestExtractor|InBodyExtractor|NutritionPlanExtractor  $agent
     * @param  callable(array<string, mixed>): void  $applyPayload
     */
    private function runExtractor(
        PlayerRecord $record,
        string $text,
        $agent,
        string $kind,
        callable $applyPayload,
        AgentRouter $router,
    ): void {
        try {
            /** @var array<string, mixed> $payload */
            $payload = $router->send($agent, $text, [
                'kind' => $kind,
                'record_id' => $record->id,
            ]);
        } catch (CallbackPendingException $e) {
            // nginx 504'd before n8n responded. n8n is still running;
            // the callback handler will close the loop. Stamp the
            // record so the Timeline shows "awaiting result" instead
            // of stale pending state.
            Log::info('Extractor sync timed out, awaiting callback', [
                'record_id' => $record->id,
                'kind' => $kind,
                'correlation_id' => $e->correlationId,
            ]);

            $extracted = $record->extracted ?? [];
            $extracted['awaiting_callback'] = true;
            $extracted['callback_correlation_id'] = $e->correlationId;
            // Clear any prior failure markers — we're back in flight.
            unset(
                $extracted['extraction_failed'],
                $extracted['extraction_failed_at'],
                $extracted['extraction_failure_reason'],
            );
            $record->update(['extracted' => $extracted]);

            return;
        }

        $applyPayload($payload);
    }

    private function markPending(PlayerRecord $record): void
    {
        $extracted = $record->extracted;
        $extracted['structured_extraction_pending'] = true;
        $record->update(['extracted' => $extracted]);
    }
}
