<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Match;

use App\Models\MatchPerformance;
use App\Models\MatchReport;
use App\Models\Player;
use App\Models\PlayerRecord;
use App\Models\RecordCategory;
use App\Models\RecordMetric;
use App\Services\Match\MatchReportApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use InvalidArgumentException;
use Tests\TestCase;

class MatchReportApplierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // applyExtraction fires the Phase-2 extractor batch (shot events
        // etc.) at the end. Fake the bus so tests don't accidentally run
        // those jobs synchronously against the real Anthropic API.
        Bus::fake();
    }

    public function test_apply_extraction_fills_match_meta_and_flips_status_to_extracted(): void
    {
        $report = MatchReport::factory()->pending()->create();

        app(MatchReportApplier::class)->applyExtraction($report, [
            'competition' => 'AGCFF U20 Arab Gulf Cup',
            'stage' => 'Group B Round 1',
            'match_date' => '2025-08-29',
            'kickoff_time' => '19:00',
            'venue' => 'Damac Stadium',
            'home_team_name' => 'Iraq U20',
            'away_team_name' => 'Bahrain U20',
            'home_score' => 1,
            'away_score' => 0,
            'performances' => [],
        ]);

        $report->refresh();
        $this->assertSame(MatchReport::STATUS_EXTRACTED, $report->status);
        $this->assertSame('AGCFF U20 Arab Gulf Cup', $report->competition);
        $this->assertSame('2025-08-29', $report->match_date?->toDateString());
        $this->assertSame('Bahrain U20', $report->away_team_name);
        $this->assertSame(MatchReport::BAHRAIN_SIDE_AWAY, $report->bahrain_side);
        $this->assertSame('Iraq U20', $report->opponent_name);
        $this->assertNotNull($report->raw_extracted);
        $this->assertNotNull($report->extracted_at);
    }

    public function test_apply_extraction_writes_team_aggregate_extras_from_payload(): void
    {
        $report = MatchReport::factory()->pending()->create();

        app(MatchReportApplier::class)->applyExtraction($report, [
            'home_team_name' => 'Iraq U20',
            'away_team_name' => 'Bahrain U20',
            'home_score' => 1,
            'away_score' => 0,
            'match_date' => '2025-08-29',
            'home_possession_pct' => 57.4,
            'away_possession_pct' => 42.6,
            'first_half_home_score' => 0,
            'first_half_away_score' => 0,
            'second_half_home_score' => 1,
            'second_half_away_score' => 0,
            'performances' => [],
        ]);

        $report->refresh();
        $this->assertEqualsWithDelta(57.4, (float) $report->home_possession_pct, 0.01);
        $this->assertEqualsWithDelta(42.6, (float) $report->away_possession_pct, 0.01);
        $this->assertSame(0, $report->first_half_home_score);
        $this->assertSame(1, $report->second_half_home_score);
        $this->assertSame(0, $report->first_half_away_score);
        $this->assertSame(0, $report->second_half_away_score);
    }

    public function test_apply_extraction_handles_possession_as_string_with_percent_sign(): void
    {
        // Defensive: extractor occasionally emits "57.4%" as a literal string
        // instead of a number. Applier must strip the % and parse, not bail.
        $report = MatchReport::factory()->pending()->create();

        app(MatchReportApplier::class)->applyExtraction($report, [
            'home_team_name' => 'Iraq U20',
            'away_team_name' => 'Bahrain U20',
            'match_date' => '2025-08-29',
            'home_possession_pct' => '57.4%',
            'away_possession_pct' => '42.6 %',
            'performances' => [],
        ]);

        $this->assertEqualsWithDelta(57.4, (float) $report->fresh()->home_possession_pct, 0.01);
        $this->assertEqualsWithDelta(42.6, (float) $report->fresh()->away_possession_pct, 0.01);
    }

    public function test_apply_extraction_rejects_out_of_range_possession(): void
    {
        $report = MatchReport::factory()->pending()->create();

        app(MatchReportApplier::class)->applyExtraction($report, [
            'home_team_name' => 'Iraq U20',
            'away_team_name' => 'Bahrain U20',
            'match_date' => '2025-08-29',
            'home_possession_pct' => 150,    // garbage
            'away_possession_pct' => -5,     // garbage
            'performances' => [],
        ]);

        $this->assertNull($report->fresh()->home_possession_pct);
        $this->assertNull($report->fresh()->away_possession_pct);
    }

    public function test_apply_extraction_detects_bahrain_as_home_side(): void
    {
        $report = MatchReport::factory()->pending()->create();

        app(MatchReportApplier::class)->applyExtraction($report, [
            'home_team_name' => 'Bahrain U20',
            'away_team_name' => 'Saudi Arabia U19',
            'home_score' => 0,
            'away_score' => 3,
            'match_date' => '2025-10-10',
            'performances' => [],
        ]);

        $report->refresh();
        $this->assertSame(MatchReport::BAHRAIN_SIDE_HOME, $report->bahrain_side);
        $this->assertSame('Saudi Arabia U19', $report->opponent_name);
    }

    public function test_apply_resolution_throws_when_no_extracted_performances(): void
    {
        $report = MatchReport::factory()->pending()->create(['raw_extracted' => null]);

        $this->expectException(InvalidArgumentException::class);

        app(MatchReportApplier::class)->applyResolution($report, []);
    }

    public function test_apply_resolution_persists_pass_breakdown_when_extractor_emits_it(): void
    {
        $player = Player::factory()->create(['full_name' => 'Mohammed Alyaqoob']);
        $report = MatchReport::factory()->create();

        // Mutate the factory payload so the first performance has a pass_breakdown.
        $raw = $report->raw_extracted;
        $raw['performances'][0]['pass_breakdown'] = [
            'by_area' => [
                'defensive_third' => ['succeeded' => 11, 'total' => 13],
                'middle_third' => ['succeeded' => 14, 'total' => 15],
                'final_third' => ['succeeded' => 4, 'total' => 6],
            ],
            'by_direction' => [
                'forward' => ['succeeded' => 0, 'total' => 0],
                'sideways' => ['succeeded' => 0, 'total' => 0],
                'backward' => ['succeeded' => 17, 'total' => 27],
            ],
            'by_length' => [
                'short' => ['succeeded' => 6, 'total' => 15],
                'medium' => ['succeeded' => 10, 'total' => 10],
                'long' => ['succeeded' => 2, 'total' => 3],
            ],
        ];
        $report->update(['raw_extracted' => $raw]);

        app(MatchReportApplier::class)->applyResolution($report, [
            0 => ['type' => 'existing', 'player_id' => $player->id],
        ]);

        $perf = MatchPerformance::where('match_report_id', $report->id)
            ->where('jersey_number', 6)
            ->firstOrFail();

        $this->assertNotNull($perf->pass_breakdown);
        $this->assertSame(11, $perf->pass_breakdown['by_area']['defensive_third']['succeeded']);
        $this->assertSame(13, $perf->pass_breakdown['by_area']['defensive_third']['total']);
        $this->assertSame(17, $perf->pass_breakdown['by_direction']['backward']['succeeded']);
        $this->assertSame(2, $perf->pass_breakdown['by_length']['long']['succeeded']);
    }

    public function test_apply_resolution_leaves_pass_breakdown_null_when_extractor_omits_it(): void
    {
        $player = Player::factory()->create();
        $report = MatchReport::factory()->create();
        // factory's default has no pass_breakdown on any performance.

        app(MatchReportApplier::class)->applyResolution($report, [
            0 => ['type' => 'existing', 'player_id' => $player->id],
        ]);

        $perf = MatchPerformance::where('match_report_id', $report->id)
            ->where('jersey_number', 6)
            ->firstOrFail();
        $this->assertNull($perf->pass_breakdown);
    }

    public function test_pass_breakdown_sanitiser_drops_malformed_subbuckets(): void
    {
        $player = Player::factory()->create();
        $report = MatchReport::factory()->create();

        // Half-garbage payload: some buckets valid, some missing keys, one with non-numeric values.
        $raw = $report->raw_extracted;
        $raw['performances'][0]['pass_breakdown'] = [
            'by_area' => [
                'defensive_third' => ['succeeded' => 5, 'total' => 7],
                'middle_third' => 'not an array',
                'final_third' => ['succeeded' => 'NaN', 'total' => 'also NaN'],
            ],
            'by_direction' => 'totally wrong',
        ];
        $report->update(['raw_extracted' => $raw]);

        app(MatchReportApplier::class)->applyResolution($report, [
            0 => ['type' => 'existing', 'player_id' => $player->id],
        ]);

        $bd = MatchPerformance::where('match_report_id', $report->id)
            ->where('jersey_number', 6)
            ->firstOrFail()
            ->pass_breakdown;

        $this->assertNotNull($bd);
        $this->assertArrayHasKey('by_area', $bd);
        $this->assertSame(5, $bd['by_area']['defensive_third']['succeeded']);
        // Malformed buckets dropped, not zeroed.
        $this->assertArrayNotHasKey('middle_third', $bd['by_area']);
        $this->assertArrayNotHasKey('final_third', $bd['by_area']);
        // Malformed grouping omitted entirely.
        $this->assertArrayNotHasKey('by_direction', $bd);
    }

    public function test_apply_resolution_writes_performance_rows_for_every_player(): void
    {
        $player = Player::factory()->create(['full_name' => 'Mohammed Alyaqoob']);
        $report = MatchReport::factory()->create();

        app(MatchReportApplier::class)->applyResolution($report, [
            0 => ['type' => 'existing', 'player_id' => $player->id],
            1 => ['type' => 'skip'],
            // index 2 (opponent row) intentionally omitted — defensive default
            // is 'skip' on the applier side.
        ]);

        $report->refresh();
        $this->assertSame(MatchReport::STATUS_APPLIED, $report->status);
        $this->assertNotNull($report->applied_at);

        // 3 rows in the factory payload → 3 match_performances rows total.
        $this->assertSame(3, MatchPerformance::where('match_report_id', $report->id)->count());

        // Resolved Bahrain row → has player_id + player_record_id.
        $resolved = MatchPerformance::where('match_report_id', $report->id)
            ->where('jersey_number', 6)
            ->firstOrFail();
        $this->assertSame($player->id, $resolved->player_id);
        $this->assertSame(MatchPerformance::TEAM_SIDE_BAHRAIN, $resolved->team_side);
        $this->assertNotNull($resolved->player_record_id);

        // Skipped Bahrain row → no player_id, no player_record_id.
        $skipped = MatchPerformance::where('match_report_id', $report->id)
            ->where('jersey_number', 5)
            ->firstOrFail();
        $this->assertNull($skipped->player_id);
        $this->assertNull($skipped->player_record_id);

        // Opponent row → no player_id ever, regardless of resolution choice.
        $opponent = MatchPerformance::where('match_report_id', $report->id)
            ->where('team_side', MatchPerformance::TEAM_SIDE_OPPONENT)
            ->firstOrFail();
        $this->assertNull($opponent->player_id);
    }

    public function test_apply_resolution_creates_player_record_in_match_performance_category(): void
    {
        $player = Player::factory()->create();
        $report = MatchReport::factory()->create();

        app(MatchReportApplier::class)->applyResolution($report, [
            0 => ['type' => 'existing', 'player_id' => $player->id],
        ]);

        $playerRecord = PlayerRecord::where('player_id', $player->id)->firstOrFail();
        $this->assertSame(RecordCategory::MATCH_PERFORMANCE, $playerRecord->category->slug);
        $this->assertSame($report->match_date->toDateString(), $playerRecord->record_date->toDateString());

        // PlayerRecord.extracted carries match context for the future
        // Performance-Analyst agent + a `metrics` array for the fanner.
        $extracted = $playerRecord->extracted;
        $this->assertSame($report->id, $extracted['match_id']);
        $this->assertSame('Iraq U20', $extracted['opponent']);
        $this->assertIsArray($extracted['metrics']);
        $this->assertNotEmpty($extracted['metrics']);
    }

    public function test_apply_resolution_fans_record_metrics_via_existing_fanner(): void
    {
        $player = Player::factory()->create();
        $report = MatchReport::factory()->create();

        app(MatchReportApplier::class)->applyResolution($report, [
            0 => ['type' => 'existing', 'player_id' => $player->id],
        ]);

        $metricKeys = RecordMetric::where('player_id', $player->id)->pluck('metric_key')->all();

        $this->assertContains('match_rating', $metricKeys);
        $this->assertContains('match_minutes_played', $metricKeys);
        $this->assertContains('match_passes_total', $metricKeys);
        $this->assertContains('match_pass_accuracy_pct', $metricKeys);

        // Verify a specific value lands as expected from the factory payload.
        $rating = RecordMetric::where('player_id', $player->id)
            ->where('metric_key', 'match_rating')
            ->value('metric_value');
        $this->assertEqualsWithDelta(6.9, (float) $rating, 0.01);
    }

    public function test_apply_resolution_can_create_new_players_inline(): void
    {
        $report = MatchReport::factory()->create();

        app(MatchReportApplier::class)->applyResolution($report, [
            1 => [
                'type' => 'new',
                'data' => [
                    'full_name' => 'Hassan Al-Sayed',
                    'position' => 'DF',
                    'nationality' => 'Bahraini',
                ],
            ],
        ]);

        $newPlayer = Player::where('full_name', 'Hassan Al-Sayed')->firstOrFail();
        $this->assertSame('DF', $newPlayer->position);

        $performance = MatchPerformance::where('match_report_id', $report->id)
            ->where('jersey_number', 5)
            ->firstOrFail();
        $this->assertSame($newPlayer->id, $performance->player_id);
    }

    public function test_new_player_resolution_without_full_name_throws(): void
    {
        $report = MatchReport::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        app(MatchReportApplier::class)->applyResolution($report, [
            1 => ['type' => 'new', 'data' => ['full_name' => '  ']],
        ]);
    }

    public function test_idempotent_reapply_wipes_prior_performances_and_player_records(): void
    {
        $playerA = Player::factory()->create();
        $playerB = Player::factory()->create();
        $report = MatchReport::factory()->create();

        // First apply: index 0 resolves to player A.
        app(MatchReportApplier::class)->applyResolution($report, [
            0 => ['type' => 'existing', 'player_id' => $playerA->id],
        ]);

        $this->assertSame(1, PlayerRecord::where('player_id', $playerA->id)->count());

        // Admin changes mind, applies again with player B instead.
        app(MatchReportApplier::class)->applyResolution($report, [
            0 => ['type' => 'existing', 'player_id' => $playerB->id],
        ]);

        // Player A's record was wiped on re-apply.
        $this->assertSame(0, PlayerRecord::where('player_id', $playerA->id)->count());
        $this->assertSame(0, RecordMetric::where('player_id', $playerA->id)->count());

        // Player B picked up the rebuilt fan-out.
        $this->assertSame(1, PlayerRecord::where('player_id', $playerB->id)->count());
        $this->assertGreaterThan(0, RecordMetric::where('player_id', $playerB->id)->count());

        // No duplicate MatchPerformance rows.
        $this->assertSame(3, MatchPerformance::where('match_report_id', $report->id)->count());
    }

    public function test_resolved_opponent_resolution_is_ignored_for_safety(): void
    {
        // Defensive: if the client somehow ships a resolution for the opponent
        // row (index 2 in the factory), the applier must NOT link a player_id
        // to it. team_side='opponent' is the source of truth, not the payload.
        $player = Player::factory()->create();
        $report = MatchReport::factory()->create();

        app(MatchReportApplier::class)->applyResolution($report, [
            2 => ['type' => 'existing', 'player_id' => $player->id],
        ]);

        $opponentRow = MatchPerformance::where('match_report_id', $report->id)
            ->where('team_side', MatchPerformance::TEAM_SIDE_OPPONENT)
            ->firstOrFail();
        $this->assertNull($opponentRow->player_id);
    }
}
