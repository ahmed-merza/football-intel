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
use Illuminate\Database\Eloquent\Collection;
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

        // Cross-domain enrichment: pull this player's most recent N
        // match-performance records so the agent can correlate medical
        // markers with on-pitch trends. Capped at 5 — older matches add
        // noise more than signal for week-to-week analysis. Missing is
        // fine; the agent's prompt covers the "no matches on file" path.
        $matchPerformances = $this->recentMatchPerformances($player, 5);

        $userPrompt = $this->buildPrompt($player, $blood, $inbody, $matchPerformances);
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

    /**
     * @return Collection<int, PlayerRecord>
     */
    private function recentMatchPerformances(Player $player, int $limit): Collection
    {
        return $player->records()
            ->whereHas('category', fn ($q) => $q->where('slug', RecordCategory::MATCH_PERFORMANCE))
            ->orderByDesc('record_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  Collection<int, PlayerRecord>  $matches
     */
    private function buildPrompt(Player $player, PlayerRecord $blood, PlayerRecord $inbody, $matches): string
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
            '',
            'RECENT MATCH PERFORMANCES (newest first, max 5)',
            $this->formatMatchPerformances($matches),
        ]);
    }

    /**
     * Compact narrative format — JSON of the full extracted payload would
     * burn ~3K tokens per match; this hits the highlights in ~150 tokens.
     * Includes pass-breakdown when it's there because the work-rate
     * profile is exactly what the nutritionist needs alongside blood/body.
     *
     * @param  Collection<int, PlayerRecord>  $matches
     */
    private function formatMatchPerformances($matches): string
    {
        if ($matches->isEmpty()) {
            return 'No recent match performances on file for this player.';
        }

        $lines = [];
        foreach ($matches as $i => $record) {
            $e = $record->extracted ?? [];
            $raw = is_array($e['raw_extracted'] ?? null) ? $e['raw_extracted'] : [];

            $context = sprintf(
                '%d. %s — vs %s%s%s',
                $i + 1,
                $record->record_date->toDateString(),
                $e['opponent'] ?? 'unknown opponent',
                isset($e['competition']) ? ' — '.$e['competition'] : '',
                isset($e['stage']) ? ' / '.$e['stage'] : '',
            );

            $role = sprintf(
                '   Role: %s #%s, %s, %s\' played, rating %s',
                $e['match_position'] ?? '?',
                $e['jersey_number'] ?? '?',
                $e['appearance'] ?? '?',
                $e['minutes_played'] ?? '?',
                isset($e['rating']) ? number_format((float) $e['rating'], 1) : '—',
            );

            $headline = sprintf(
                '   Headline: %dG/%dA, %d shots (%d on target), %d key passes, %d/%d passes (%s%%)',
                (int) ($raw['goals'] ?? 0),
                (int) ($raw['assists'] ?? 0),
                (int) ($raw['shots'] ?? 0),
                (int) ($raw['shots_on_target'] ?? 0),
                (int) ($raw['key_passes'] ?? 0),
                (int) ($raw['passes_succeeded'] ?? 0),
                (int) ($raw['passes_total'] ?? 0),
                isset($raw['pass_accuracy_pct']) ? number_format((float) $raw['pass_accuracy_pct'], 1) : '—',
            );

            $defense = sprintf(
                '   Defensive: tackles %d/%d, aerial %d/%d, ground %d/%d, recoveries %d, clearances %d, interceptions %d',
                (int) ($raw['tackles_succeeded'] ?? 0),
                (int) ($raw['tackles_attempted'] ?? 0),
                (int) ($raw['aerial_duels_won'] ?? 0),
                (int) ($raw['aerial_duels_total'] ?? 0),
                (int) ($raw['ground_duels_won'] ?? 0),
                (int) ($raw['ground_duels_total'] ?? 0),
                (int) ($raw['recoveries'] ?? 0),
                (int) ($raw['clearances'] ?? 0),
                (int) ($raw['interceptions'] ?? 0),
            );

            $discipline = sprintf(
                '   Discipline: %d fouls (won %d), %d yellow, %d red',
                (int) ($raw['fouls_committed'] ?? 0),
                (int) ($raw['fouls_won'] ?? 0),
                (int) ($raw['yellow_cards'] ?? 0),
                (int) ($raw['red_cards'] ?? 0),
            );

            $lines[] = $context;
            $lines[] = $role;
            $lines[] = $headline;
            $lines[] = $defense;
            $lines[] = $discipline;

            // Pass breakdown when present — work-rate signal (deep vs.
            // creator role, short-passer vs. long-ball). Compact one-liner.
            $bd = is_array($raw['pass_breakdown'] ?? null) ? $raw['pass_breakdown'] : null;
            if ($bd !== null) {
                $lines[] = '   Pass profile: '.$this->formatPassBreakdown($bd);
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $bd
     */
    private function formatPassBreakdown(array $bd): string
    {
        $segments = [];
        if (is_array($bd['by_area'] ?? null)) {
            $segments[] = 'area '.$this->formatBucketRow($bd['by_area'], ['defensive_third', 'middle_third', 'final_third']);
        }
        if (is_array($bd['by_direction'] ?? null)) {
            $segments[] = 'dir '.$this->formatBucketRow($bd['by_direction'], ['forward', 'sideways', 'backward']);
        }
        if (is_array($bd['by_length'] ?? null)) {
            $segments[] = 'len '.$this->formatBucketRow($bd['by_length'], ['short', 'medium', 'long']);
        }

        return implode(' | ', $segments);
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  list<string>  $order
     */
    private function formatBucketRow(array $group, array $order): string
    {
        $pieces = [];
        foreach ($order as $key) {
            $b = is_array($group[$key] ?? null) ? $group[$key] : null;
            if ($b === null) {
                continue;
            }
            $pieces[] = sprintf('%d/%d', (int) ($b['succeeded'] ?? 0), (int) ($b['total'] ?? 0));
        }

        return implode(' ', $pieces);
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
