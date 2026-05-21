<?php

declare(strict_types=1);

namespace App\Services\Match;

use App\Http\Controllers\N8nWebhookController;
use App\Jobs\ExtractMatchShotEventsJob;
use App\Models\MatchPerformance;
use App\Models\MatchReport;
use App\Models\MatchShotEvent;
use Illuminate\Support\Facades\DB;

/**
 * Writes the extracted Shot Details payload to {@see MatchShotEvent}.
 * Both code paths converge here:
 *
 *   - sync success inside {@see ExtractMatchShotEventsJob}
 *   - async callback via {@see N8nWebhookController}
 *
 * Idempotent: a re-extract wipes prior rows for the same match_report
 * first, so partial / stale data never accumulates.
 */
class MatchShotEventsApplier
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function apply(MatchReport $report, array $payload): void
    {
        DB::transaction(function () use ($report, $payload): void {
            // Idempotent wipe — running the same extraction again should
            // replace prior data, not accumulate it. We pick the simpler
            // hard-delete since shot events are 100% derived from the PDF.
            MatchShotEvent::where('match_report_id', $report->id)->delete();

            // Widened from array<int, array> because the data comes from
            // a JSON column — runtime is_array() check inside the loop is
            // load-bearing if the extractor misbehaves.
            /** @var array<int, mixed> $shots */
            $shots = (array) ($payload['shots'] ?? []);

            foreach ($shots as $shot) {
                if (! is_array($shot)) {
                    continue;
                }

                $teamSide = $this->resolveTeamSide($report, $shot['team_side'] ?? null);

                MatchShotEvent::create([
                    'match_report_id' => $report->id,
                    'sequence' => $this->int($shot['sequence'] ?? null) ?? 0,
                    'minute' => $this->int($shot['minute'] ?? null) ?? 0,
                    'seconds' => $this->int($shot['seconds'] ?? null),
                    'team_side' => $teamSide,
                    'jersey_number' => $this->int($shot['jersey_number'] ?? null) ?? 0,
                    'reported_name' => $this->str($shot['reported_name'] ?? null),
                    'body_part' => $this->str($shot['body_part'] ?? null),
                    'outcome' => $this->normaliseOutcome($shot['outcome'] ?? null),
                    'is_penalty' => (bool) ($shot['is_penalty'] ?? false),
                    'is_own_goal' => (bool) ($shot['is_own_goal'] ?? false),
                    'buildup_chain' => is_array($shot['buildup_chain'] ?? null)
                        ? $shot['buildup_chain']
                        : null,
                    'raw_extracted' => $shot,
                ]);
            }
        });
    }

    /**
     * Map the extractor's 'home'/'away' onto our 'bahrain'/'opponent'
     * convention so the (match_report_id, team_side, jersey_number) join
     * to match_performances works without translation.
     */
    private function resolveTeamSide(MatchReport $report, mixed $extractorSide): string
    {
        return $extractorSide === $report->bahrain_side
            ? MatchPerformance::TEAM_SIDE_BAHRAIN
            : MatchPerformance::TEAM_SIDE_OPPONENT;
    }

    /**
     * Coerce the extractor's outcome string to one of our canonical values.
     * Unknowns land as 'other' rather than throwing — the raw value is
     * preserved on raw_extracted for forensics.
     */
    private function normaliseOutcome(mixed $value): string
    {
        if (! is_string($value)) {
            return MatchShotEvent::OUTCOME_OTHER;
        }
        $normalised = strtolower(trim($value));

        return match ($normalised) {
            'goal', 'goals' => MatchShotEvent::OUTCOME_GOAL,
            'on_target', 'on target', 'shots on target' => MatchShotEvent::OUTCOME_ON_TARGET,
            'blocked', 'blocked shots' => MatchShotEvent::OUTCOME_BLOCKED,
            'missed', 'miss', 'missed shot', 'shots / missed shot' => MatchShotEvent::OUTCOME_MISSED,
            default => MatchShotEvent::OUTCOME_OTHER,
        };
    }

    private function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
