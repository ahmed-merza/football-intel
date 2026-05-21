<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Match;

use App\Models\MatchPerformance;
use App\Models\MatchReport;
use App\Models\MatchShotEvent;
use App\Services\Match\MatchShotEventsApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MatchShotEventsApplierTest extends TestCase
{
    use RefreshDatabase;

    public function test_writes_one_row_per_shot_with_team_side_mapped_to_domain(): void
    {
        // bahrain_side='away' on the report → extractor's 'home' becomes
        // 'opponent' in our domain; 'away' becomes 'bahrain'.
        $report = MatchReport::factory()->create(['bahrain_side' => 'away']);

        app(MatchShotEventsApplier::class)->apply($report, [
            'shots' => [
                [
                    'sequence' => 1, 'minute' => 7,
                    'team_side' => 'home', 'jersey_number' => 6,
                    'reported_name' => 'Ali Muneam', 'body_part' => 'Right Foot',
                    'outcome' => 'missed',
                ],
                [
                    'sequence' => 2, 'minute' => 13,
                    'team_side' => 'away', 'jersey_number' => 9,
                    'reported_name' => 'Mohammed Al Battat',
                    'body_part' => 'Left Foot',
                    'outcome' => 'goal',
                ],
            ],
        ]);

        $rows = MatchShotEvent::where('match_report_id', $report->id)
            ->orderBy('sequence')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame(MatchPerformance::TEAM_SIDE_OPPONENT, $rows[0]->team_side);
        $this->assertSame(MatchPerformance::TEAM_SIDE_BAHRAIN, $rows[1]->team_side);
        $this->assertSame('goal', $rows[1]->outcome);
    }

    public function test_preserves_buildup_chain_as_json_array(): void
    {
        $report = MatchReport::factory()->create(['bahrain_side' => 'away']);
        $buildup = [
            ['jersey_number' => 23, 'reported_name' => 'Mohsin', 'actions' => ['Buildup Start', 'Recoveries']],
            ['jersey_number' => 6, 'reported_name' => 'Muneam', 'actions' => ['Shots', 'Right Foot', 'Buildup End']],
        ];

        app(MatchShotEventsApplier::class)->apply($report, [
            'shots' => [[
                'sequence' => 1, 'minute' => 7,
                'team_side' => 'home', 'jersey_number' => 6,
                'reported_name' => 'Muneam', 'outcome' => 'missed',
                'buildup_chain' => $buildup,
            ]],
        ]);

        $stored = MatchShotEvent::where('match_report_id', $report->id)->firstOrFail();
        $this->assertSame($buildup, $stored->buildup_chain);
    }

    public function test_normalises_outcome_strings_to_canonical_constants(): void
    {
        $report = MatchReport::factory()->create(['bahrain_side' => 'away']);

        app(MatchShotEventsApplier::class)->apply($report, [
            'shots' => [
                ['sequence' => 1, 'minute' => 5, 'team_side' => 'home', 'jersey_number' => 1, 'reported_name' => 'X', 'outcome' => 'GOAL'],
                ['sequence' => 2, 'minute' => 10, 'team_side' => 'home', 'jersey_number' => 2, 'reported_name' => 'X', 'outcome' => 'on target'],
                ['sequence' => 3, 'minute' => 15, 'team_side' => 'home', 'jersey_number' => 3, 'reported_name' => 'X', 'outcome' => 'Blocked Shots'],
                ['sequence' => 4, 'minute' => 20, 'team_side' => 'home', 'jersey_number' => 4, 'reported_name' => 'X', 'outcome' => 'something weird'],
            ],
        ]);

        $rows = MatchShotEvent::where('match_report_id', $report->id)->orderBy('sequence')->pluck('outcome')->all();
        $this->assertSame([
            MatchShotEvent::OUTCOME_GOAL,
            MatchShotEvent::OUTCOME_ON_TARGET,
            MatchShotEvent::OUTCOME_BLOCKED,
            MatchShotEvent::OUTCOME_OTHER,
        ], $rows);
    }

    public function test_idempotent_re_apply_wipes_prior_rows(): void
    {
        $report = MatchReport::factory()->create(['bahrain_side' => 'away']);

        app(MatchShotEventsApplier::class)->apply($report, [
            'shots' => [
                ['sequence' => 1, 'minute' => 5, 'team_side' => 'home', 'jersey_number' => 1, 'reported_name' => 'X', 'outcome' => 'goal'],
                ['sequence' => 2, 'minute' => 10, 'team_side' => 'home', 'jersey_number' => 2, 'reported_name' => 'Y', 'outcome' => 'on_target'],
            ],
        ]);
        $this->assertSame(2, MatchShotEvent::where('match_report_id', $report->id)->count());

        // Re-apply with one shot → prior two are wiped, only one remains.
        app(MatchShotEventsApplier::class)->apply($report, [
            'shots' => [
                ['sequence' => 1, 'minute' => 8, 'team_side' => 'away', 'jersey_number' => 9, 'reported_name' => 'Z', 'outcome' => 'goal'],
            ],
        ]);

        $rows = MatchShotEvent::where('match_report_id', $report->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(9, (int) $rows[0]->jersey_number);
    }

    public function test_empty_shots_payload_is_a_clean_no_op(): void
    {
        $report = MatchReport::factory()->create(['bahrain_side' => 'away']);

        app(MatchShotEventsApplier::class)->apply($report, ['shots' => []]);
        app(MatchShotEventsApplier::class)->apply($report, []);

        $this->assertSame(0, MatchShotEvent::where('match_report_id', $report->id)->count());
    }
}
