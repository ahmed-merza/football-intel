<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\MatchReport;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Produces realistic match_reports rows for the test suite. Default state
 * is `extracted` (admin-ready) with a full performances payload so the
 * resolution + apply tests can run without manually crafting the JSON
 * every time. State methods cover other lifecycle steps.
 *
 * @extends Factory<MatchReport>
 */
class MatchReportFactory extends Factory
{
    protected $model = MatchReport::class;

    public function definition(): array
    {
        return [
            'source' => MatchReport::SOURCE_AGCFF,
            'competition' => 'AGCFF U20 Arab Gulf Cup',
            'stage' => 'Group B Round 1',
            'match_date' => '2025-08-29',
            'kickoff_time' => '19:00',
            'venue' => 'Damac Stadium',
            'home_team_name' => 'Iraq U20',
            'away_team_name' => 'Bahrain U20',
            'home_score' => 1,
            'away_score' => 0,
            'bahrain_side' => MatchReport::BAHRAIN_SIDE_AWAY,
            'opponent_name' => 'Iraq U20',
            'submission_id' => Submission::factory(),
            'attachment_id' => Attachment::factory(),
            'uploaded_by' => User::factory(),
            'status' => MatchReport::STATUS_EXTRACTED,
            'extraction_error' => null,
            'raw_extracted' => $this->fullExtractedPayload(),
            'extracted_at' => now(),
            'applied_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => MatchReport::STATUS_PENDING,
            'raw_extracted' => null,
            'extracted_at' => null,
            'competition' => null,
            'stage' => null,
            'match_date' => null,
            'venue' => null,
            'home_team_name' => null,
            'away_team_name' => null,
            'home_score' => null,
            'away_score' => null,
            'bahrain_side' => null,
            'opponent_name' => null,
        ]);
    }

    public function awaitingCallback(): static
    {
        return $this->state(fn (): array => [
            'status' => MatchReport::STATUS_AWAITING_CALLBACK,
            'raw_extracted' => null,
            'extracted_at' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => MatchReport::STATUS_FAILED,
            'extraction_error' => 'Extractor returned non-JSON: claude died',
        ]);
    }

    public function applied(): static
    {
        return $this->state(fn (): array => [
            'status' => MatchReport::STATUS_APPLIED,
            'applied_at' => now(),
        ]);
    }

    /**
     * Minimal-but-valid extractor output. Two Bahrain rows (one named,
     * one anonymised "Player 5") + one opponent row keep the resolution
     * scenarios crisp.
     *
     * @return array<string, mixed>
     */
    private function fullExtractedPayload(): array
    {
        return [
            'competition' => 'AGCFF U20 Arab Gulf Cup',
            'stage' => 'Group B Round 1',
            'match_date' => '2025-08-29',
            'kickoff_time' => '19:00',
            'venue' => 'Damac Stadium',
            'home_team_name' => 'Iraq U20',
            'away_team_name' => 'Bahrain U20',
            'home_score' => 1,
            'away_score' => 0,
            'scorers' => [
                ['minute' => 53, 'team_side' => 'home', 'jersey_number' => 13, 'name' => 'Flayyih Alsuhaibi', 'penalty' => false, 'own_goal' => false],
            ],
            'performances' => [
                $this->performance([
                    'team_side' => 'away',
                    'reported_name' => 'Mohammed Alyaqoob',
                    'jersey_number' => 6,
                    'match_position' => 'CB',
                    'appearance' => 'starter',
                    'minutes_played' => 90,
                    'rating' => 6.9,
                    'passes_total' => 30,
                    'passes_succeeded' => 28,
                    'pass_accuracy_pct' => 93.3,
                ]),
                $this->performance([
                    'team_side' => 'away',
                    'reported_name' => 'Player 5',
                    'jersey_number' => 5,
                    'match_position' => 'CB',
                    'appearance' => 'sub',
                    'minute_on' => 19,
                    'minute_off' => 90,
                    'minutes_played' => 72,
                    'rating' => 7.2,
                    'tackles_attempted' => 4,
                    'tackles_succeeded' => 3,
                ]),
                $this->performance([
                    'team_side' => 'home',
                    'reported_name' => 'Yasir Abboodi',
                    'jersey_number' => 10,
                    'match_position' => 'RW',
                    'appearance' => 'starter',
                    'minutes_played' => 71,
                    'rating' => 8.1,
                    'goals' => 0,
                    'assists' => 1,
                ]),
            ],
        ];
    }

    /**
     * Default-zero per-player row that callers can override per-test.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function performance(array $overrides): array
    {
        $defaults = [
            'team_side' => 'away',
            'reported_name' => 'Test Player',
            'jersey_number' => 99,
            'match_position' => 'CM',
            'appearance' => 'starter',
            'minute_on' => 0,
            'minute_off' => 90,
            'minutes_played' => 90,
            'rating' => 6.5,
            'goals' => 0, 'assists' => 0,
            'shots' => 0, 'shots_on_target' => 0, 'shots_blocked' => 0, 'shots_missed' => 0,
            'shots_inside_pa' => 0, 'shots_outside_pa' => 0,
            'offsides' => 0, 'freekicks_taken' => 0, 'corners_taken' => 0, 'throw_ins' => 0,
            'take_ons_attempted' => 0, 'take_ons_succeeded' => 0,
            'passes_total' => 0, 'passes_succeeded' => 0, 'pass_accuracy_pct' => null,
            'key_passes' => 0, 'crosses_attempted' => 0, 'crosses_succeeded' => 0,
            'controls_under_pressure' => 0,
            'tackles_attempted' => 0, 'tackles_succeeded' => 0,
            'aerial_duels_total' => 0, 'aerial_duels_won' => 0,
            'ground_duels_total' => 0, 'ground_duels_won' => 0,
            'interceptions' => 0, 'clearances' => 0, 'interventions' => 0,
            'recoveries' => 0, 'blocks' => 0, 'mistakes' => 0,
            'fouls_committed' => 0, 'fouls_won' => 0, 'yellow_cards' => 0, 'red_cards' => 0,
        ];

        return array_merge($defaults, $overrides);
    }
}
