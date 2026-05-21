<?php

declare(strict_types=1);

namespace App\Services\Match;

use App\Models\MatchGoalkeeperEvent;
use App\Models\MatchPerformance;
use App\Models\MatchReport;
use Illuminate\Support\Facades\DB;

/**
 * Writes the extracted Goalkeeper events payload to
 * {@see MatchGoalkeeperEvent}. Mirrors {@see MatchShotEventsApplier}:
 * idempotent wipe + re-insert, team_side mapped from extractor's
 * home/away to our bahrain/opponent convention.
 */
class MatchGoalkeeperEventsApplier
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function apply(MatchReport $report, array $payload): void
    {
        DB::transaction(function () use ($report, $payload): void {
            MatchGoalkeeperEvent::where('match_report_id', $report->id)->delete();

            // Widened from array<int, array> because the data comes from
            // a JSON column — runtime is_array() check is load-bearing.
            /** @var array<int, mixed> $events */
            $events = (array) ($payload['events'] ?? []);

            foreach ($events as $event) {
                if (! is_array($event)) {
                    continue;
                }

                MatchGoalkeeperEvent::create([
                    'match_report_id' => $report->id,
                    'sequence' => $this->int($event['sequence'] ?? null) ?? 0,
                    'minute' => $this->int($event['minute'] ?? null) ?? 0,
                    'team_side' => $this->resolveTeamSide($report, $event['team_side'] ?? null),
                    'jersey_number' => $this->int($event['jersey_number'] ?? null) ?? 0,
                    'reported_name' => $this->str($event['reported_name'] ?? null),
                    'outcome' => $this->normaliseOutcome($event['outcome'] ?? null),
                    'opponent_reported_name' => $this->str($event['opponent_reported_name'] ?? null),
                    'opponent_jersey_number' => $this->int($event['opponent_jersey_number'] ?? null),
                    'body_part' => $this->str($event['body_part'] ?? null),
                    'is_penalty' => (bool) ($event['is_penalty'] ?? false),
                    'raw_extracted' => $event,
                ]);
            }
        });
    }

    private function resolveTeamSide(MatchReport $report, mixed $extractorSide): string
    {
        return $extractorSide === $report->bahrain_side
            ? MatchPerformance::TEAM_SIDE_BAHRAIN
            : MatchPerformance::TEAM_SIDE_OPPONENT;
    }

    private function normaliseOutcome(mixed $value): string
    {
        if (! is_string($value)) {
            return MatchGoalkeeperEvent::OUTCOME_OTHER;
        }
        $normalised = strtolower(trim($value));

        return match ($normalised) {
            'catch', 'caught', 'catches' => MatchGoalkeeperEvent::OUTCOME_CATCH,
            'parry', 'parried', 'parries' => MatchGoalkeeperEvent::OUTCOME_PARRY,
            'conceded', 'goal', 'goal conceded' => MatchGoalkeeperEvent::OUTCOME_CONCEDED,
            'own_goal', 'own goal' => MatchGoalkeeperEvent::OUTCOME_OWN_GOAL,
            default => MatchGoalkeeperEvent::OUTCOME_OTHER,
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
