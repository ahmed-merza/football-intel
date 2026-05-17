<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Agents\NutritionistAssistant;
use App\Models\NutritionistAnalysis;
use App\Models\PendingExtraction;
use App\Models\Player;
use App\Models\PlayerRecord;
use App\Models\RecordCategory;
use App\Services\Ai\AgentErrorMessage;
use App\Services\Ai\AgentRouter;
use App\Services\Ai\CallbackPendingException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs the Nutritionist Assistant agent against a player's latest blood
 * + body-composition records and persists the structured output to a
 * pre-allocated NutritionistAnalysis row (status=pending → completed
 * or failed).
 *
 * The controller creates the pending row before dispatching, so the UI
 * always has a placeholder to render while the agent thinks. Failure
 * paths get captured here AND in failed() so the row never gets
 * stranded in pending state.
 */
class GenerateNutritionistAnalysisJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $analysisId)
    {
        $this->onQueue('llm');
    }

    /**
     * Last-resort hook for timeout kills + worker restarts that the
     * try/catch inside handle() can't see. Without this, an HTTP-
     * timeout death leaves the analysis row stuck on pending.
     */
    public function failed(Throwable $exception): void
    {
        $analysis = NutritionistAnalysis::find($this->analysisId);
        if ($analysis === null || $analysis->status !== NutritionistAnalysis::STATUS_PENDING) {
            return;
        }

        Log::error('NutritionistAssistant failed (worker hook)', [
            'analysis_id' => $analysis->id,
            'exception' => $exception->getMessage(),
        ]);

        $analysis->update([
            'status' => NutritionistAnalysis::STATUS_FAILED,
            'error' => AgentErrorMessage::humanise($exception),
        ]);
    }

    public function handle(AgentRouter $router): void
    {
        $analysis = NutritionistAnalysis::find($this->analysisId);
        if ($analysis === null) {
            return;
        }

        // Self-healing invariant for retries (CLI queue:retry today, a UI
        // retry button later): while this job is running the row must
        // show pending. A retry on a previously-failed row resets the
        // error so the UI stops shouting "Analysis failed" while the new
        // attempt is actually in flight. A retry on a row that's already
        // completed (callback landed, manual fix, whatever) is a no-op —
        // we don't clobber known-good results.
        if ($analysis->status === NutritionistAnalysis::STATUS_COMPLETED) {
            return;
        }
        if ($analysis->status === NutritionistAnalysis::STATUS_FAILED) {
            $analysis->update([
                'status' => NutritionistAnalysis::STATUS_PENDING,
                'error' => null,
            ]);
        }

        $player = $analysis->player;
        if ($player === null) {
            $this->markFailed($analysis, 'Player no longer exists.');

            return;
        }

        $blood = $this->latestRecordOfCategory($player, RecordCategory::BLOOD_TEST);
        $inbody = $this->latestRecordOfCategory($player, RecordCategory::INBODY);

        if ($blood === null || $inbody === null) {
            $this->markFailed(
                $analysis,
                'Need at least one blood test AND one body-composition record on file.',
            );

            return;
        }

        $analysis->update([
            'source_blood_record_id' => $blood->id,
            'source_inbody_record_id' => $inbody->id,
        ]);

        $userPrompt = $this->buildPrompt($player, $blood, $inbody);
        $agent = new NutritionistAssistant;

        try {
            /** @var array<string, mixed> $payload */
            $payload = $router->send($agent, $userPrompt, [
                'kind' => PendingExtraction::KIND_NUTRITIONIST_ANALYSIS,
                'analysis_id' => $analysis->id,
            ]);
        } catch (CallbackPendingException $e) {
            // Same async-insurance path as the extractor jobs: the sync
            // call timed out but n8n's still working upstream and will
            // hit our callback when done. Leave the analysis row in
            // pending state — the webhook handler will promote it to
            // completed (or the reaper will mark it failed if no callback
            // ever lands). No Horizon retry; that would just re-run the
            // expensive call.
            Log::info('NutritionistAssistant sync timed out, awaiting callback', [
                'analysis_id' => $analysis->id,
                'correlation_id' => $e->correlationId,
            ]);

            return;
        } catch (Throwable $e) {
            Log::error('NutritionistAssistant threw', [
                'analysis_id' => $analysis->id,
                'exception' => $e->getMessage(),
            ]);
            $this->markFailed($analysis, AgentErrorMessage::humanise($e));

            throw $e;
        }

        $analysis->update([
            'status' => NutritionistAnalysis::STATUS_COMPLETED,
            'model_used' => $agent->model(),
            'summary_text' => is_string($payload['summary'] ?? null)
                ? mb_substr($payload['summary'], 0, 1000)
                : null,
            'payload' => $payload,
            'generated_at' => now(),
            'error' => null,
        ]);
    }

    private function latestRecordOfCategory(Player $player, string $slug): ?PlayerRecord
    {
        return $player->records()
            ->whereHas('category', fn ($q) => $q->where('slug', $slug))
            ->orderByDesc('record_date')
            ->orderByDesc('id')
            ->first();
    }

    private function buildPrompt(Player $player, PlayerRecord $blood, PlayerRecord $inbody): string
    {
        $profileLines = [
            "Name: {$player->full_name}",
            'Position: '.($player->position ?? 'unknown'),
            'Age: '.($player->age !== null ? $player->age.' years' : 'unknown'),
            'Height: '.($player->height_cm !== null ? $player->height_cm.' cm' : 'unknown'),
            'Weight: '.($player->weight_kg !== null ? $player->weight_kg.' kg' : 'unknown'),
            'Preferred foot: '.($player->preferred_foot ?? 'unknown'),
        ];

        return implode("\n", [
            'PLAYER PROFILE',
            implode("\n", $profileLines),
            '',
            "BLOOD TEST ({$blood->record_date->toDateString()}, ".($blood->source_lab ?? 'lab unspecified').')',
            $this->formatJsonForPrompt($blood->extracted ?? []),
            '',
            "BODY COMPOSITION ({$inbody->record_date->toDateString()})",
            $this->formatJsonForPrompt($inbody->extracted ?? []),
        ]);
    }

    /**
     * Pretty-print the typed extracted payload so the agent can read it
     * cleanly. JSON over plain narrative because the analyte structure
     * (name / value / unit / ref / flag) survives the round trip and is
     * what the agent needs to reason about.
     *
     * @param  array<string, mixed>  $payload
     */
    private function formatJsonForPrompt(array $payload): string
    {
        // Drop classifier breadcrumb + extraction lifecycle flags — they
        // are pipeline metadata, not signal for the nutritionist.
        unset(
            $payload['classifier'],
            $payload['needs_structured_extraction'],
            $payload['extraction_failed'],
            $payload['extraction_failed_at'],
            $payload['extraction_failure_reason'],
            $payload['structured_extraction_pending'],
            $payload['extraction_partial'],
            $payload['chunks_completed'],
            $payload['chunks_total'],
            $payload['failed_chunk_index'],
            $payload['split_chunks'],
        );

        return json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) ?: '{}';
    }

    private function markFailed(NutritionistAnalysis $analysis, string $reason): void
    {
        $analysis->update([
            'status' => NutritionistAnalysis::STATUS_FAILED,
            'error' => mb_substr($reason, 0, 500),
        ]);
    }
}
