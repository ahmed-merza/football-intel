<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Match;

use App\Models\MatchGoalkeeperEvent;
use App\Models\MatchPerformance;
use App\Models\MatchReport;
use App\Services\Match\MatchGoalkeeperEventsApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MatchGoalkeeperEventsApplierTest extends TestCase
{
    use RefreshDatabase;

    public function test_writes_one_row_per_save_with_team_side_mapped(): void
    {
        // bahrain_side='away' → extractor's 'home' → 'opponent'; 'away' → 'bahrain'.
        $report = MatchReport::factory()->create(['bahrain_side' => 'away']);

        app(MatchGoalkeeperEventsApplier::class)->apply($report, [
            'events' => [
                [
                    'sequence' => 1, 'minute' => 57,
                    'team_side' => 'home', 'jersey_number' => 12,
                    'reported_name' => 'Sajjad Shuhaib',
                    'outcome' => 'parry',
                    'opponent_jersey_number' => 10, 'opponent_reported_name' => 'Khalid Alkhaldi',
                    'body_part' => 'Right Foot',
                ],
                [
                    'sequence' => 2, 'minute' => 70,
                    'team_side' => 'home', 'jersey_number' => 12,
                    'reported_name' => 'Sajjad Shuhaib',
                    'outcome' => 'parry',
                    'opponent_jersey_number' => 11, 'opponent_reported_name' => 'Player 11',
                    'body_part' => 'Right Foot',
                ],
            ],
        ]);

        $rows = MatchGoalkeeperEvent::where('match_report_id', $report->id)
            ->orderBy('sequence')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame(MatchPerformance::TEAM_SIDE_OPPONENT, $rows[0]->team_side);
        $this->assertSame('parry', $rows[0]->outcome);
        $this->assertSame('Khalid Alkhaldi', $rows[0]->opponent_reported_name);
    }

    public function test_normalises_outcome_strings_to_canonical_constants(): void
    {
        $report = MatchReport::factory()->create(['bahrain_side' => 'away']);

        app(MatchGoalkeeperEventsApplier::class)->apply($report, [
            'events' => [
                ['sequence' => 1, 'minute' => 5, 'team_side' => 'home', 'jersey_number' => 1, 'outcome' => 'CATCH'],
                ['sequence' => 2, 'minute' => 10, 'team_side' => 'home', 'jersey_number' => 1, 'outcome' => 'parries'],
                ['sequence' => 3, 'minute' => 15, 'team_side' => 'home', 'jersey_number' => 1, 'outcome' => 'goal conceded'],
                ['sequence' => 4, 'minute' => 20, 'team_side' => 'home', 'jersey_number' => 1, 'outcome' => 'own goal'],
                ['sequence' => 5, 'minute' => 25, 'team_side' => 'home', 'jersey_number' => 1, 'outcome' => 'something weird'],
            ],
        ]);

        $rows = MatchGoalkeeperEvent::where('match_report_id', $report->id)
            ->orderBy('sequence')
            ->pluck('outcome')
            ->all();
        $this->assertSame([
            MatchGoalkeeperEvent::OUTCOME_CATCH,
            MatchGoalkeeperEvent::OUTCOME_PARRY,
            MatchGoalkeeperEvent::OUTCOME_CONCEDED,
            MatchGoalkeeperEvent::OUTCOME_OWN_GOAL,
            MatchGoalkeeperEvent::OUTCOME_OTHER,
        ], $rows);
    }

    public function test_idempotent_re_apply_wipes_prior_rows(): void
    {
        $report = MatchReport::factory()->create(['bahrain_side' => 'away']);

        app(MatchGoalkeeperEventsApplier::class)->apply($report, [
            'events' => [
                ['sequence' => 1, 'minute' => 5, 'team_side' => 'home', 'jersey_number' => 1, 'outcome' => 'catch'],
                ['sequence' => 2, 'minute' => 10, 'team_side' => 'home', 'jersey_number' => 1, 'outcome' => 'parry'],
            ],
        ]);
        $this->assertSame(2, MatchGoalkeeperEvent::where('match_report_id', $report->id)->count());

        app(MatchGoalkeeperEventsApplier::class)->apply($report, [
            'events' => [
                ['sequence' => 1, 'minute' => 25, 'team_side' => 'away', 'jersey_number' => 1, 'outcome' => 'parry'],
            ],
        ]);

        $rows = MatchGoalkeeperEvent::where('match_report_id', $report->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(MatchPerformance::TEAM_SIDE_BAHRAIN, $rows[0]->team_side);
    }

    public function test_empty_events_payload_is_a_clean_no_op(): void
    {
        $report = MatchReport::factory()->create(['bahrain_side' => 'away']);

        app(MatchGoalkeeperEventsApplier::class)->apply($report, ['events' => []]);
        app(MatchGoalkeeperEventsApplier::class)->apply($report, []);

        $this->assertSame(0, MatchGoalkeeperEvent::where('match_report_id', $report->id)->count());
    }
}
