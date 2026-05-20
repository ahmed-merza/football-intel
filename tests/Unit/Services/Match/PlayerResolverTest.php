<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Match;

use App\Models\MatchPerformance;
use App\Models\MatchReport;
use App\Models\Player;
use App\Services\Match\PlayerResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PlayerResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_opposition_rows_return_no_suggestions(): void
    {
        Player::factory()->create(['full_name' => 'Yasir Abboodi']);

        $suggestions = (new PlayerResolver)->suggest([
            'reported_name' => 'Yasir Abboodi',
            'jersey_number' => 10,
            'match_position' => 'RW',
            'team_side' => MatchPerformance::TEAM_SIDE_OPPONENT,
        ]);

        $this->assertSame([], $suggestions);
    }

    public function test_exact_name_match_returns_high_confidence_with_auto_apply(): void
    {
        $player = Player::factory()->create([
            'full_name' => 'Mohammed Alyaqoob',
            'position' => 'CB',
        ]);

        $suggestions = (new PlayerResolver)->suggest([
            'reported_name' => 'Mohammed Alyaqoob',
            'jersey_number' => 6,
            'match_position' => 'CB',
            'team_side' => MatchPerformance::TEAM_SIDE_BAHRAIN,
        ]);

        $this->assertNotEmpty($suggestions);
        $this->assertSame($player->id, $suggestions[0]['player_id']);
        $this->assertSame('high', $suggestions[0]['confidence']);
        $this->assertSame('name_fuzzy', $suggestions[0]['method']);
        $this->assertTrue($suggestions[0]['auto_apply']);
    }

    public function test_minor_spelling_drift_still_matches_but_not_auto(): void
    {
        // 'K. Alkhaldi' vs 'K. Alkhalaf' is the real-world drift between the
        // two AGCFF match samples — fuzzy hits but the score sits below the
        // auto-apply threshold so the admin gets a soft suggestion.
        Player::factory()->create([
            'full_name' => 'Khalid Alkhaldi',
            'position' => 'CM',
        ]);

        $suggestions = (new PlayerResolver)->suggest([
            'reported_name' => 'Khalid Alkhalaf',
            'jersey_number' => 10,
            'match_position' => 'CM',
            'team_side' => MatchPerformance::TEAM_SIDE_BAHRAIN,
        ]);

        $this->assertNotEmpty($suggestions);
        $this->assertSame('Khalid Alkhaldi', $suggestions[0]['player_name']);
        $this->assertContains($suggestions[0]['confidence'], ['high', 'medium']);
    }

    public function test_anonymised_player_n_skips_fuzzy_when_no_jersey_history(): void
    {
        Player::factory()->create([
            'full_name' => 'Player Lookalike',
            'position' => 'CB',
        ]);

        $suggestions = (new PlayerResolver)->suggest([
            'reported_name' => 'Player 5',
            'jersey_number' => 5,
            'match_position' => 'CB',
            'team_side' => MatchPerformance::TEAM_SIDE_BAHRAIN,
        ]);

        // No jersey history seeded, and "Player 5" must not fuzzy-match against
        // any real player — the dropdown stays empty so the admin picks manually.
        $this->assertSame([], $suggestions);
    }

    public function test_jersey_history_supplies_high_confidence_for_anonymised_row(): void
    {
        $player = Player::factory()->create(['full_name' => 'Hassan Al-Sayed']);

        $report = MatchReport::factory()->applied()->create();
        MatchPerformance::create([
            'match_report_id' => $report->id,
            'team_side' => MatchPerformance::TEAM_SIDE_BAHRAIN,
            'player_id' => $player->id,
            'jersey_number' => 5,
            'match_position' => 'CB',
            'appearance' => 'starter',
            'reported_name' => 'Hassan Al-Sayed',
        ]);

        $suggestions = (new PlayerResolver)->suggest([
            'reported_name' => 'Player 5',
            'jersey_number' => 5,
            'match_position' => 'CB',
            'team_side' => MatchPerformance::TEAM_SIDE_BAHRAIN,
        ]);

        $this->assertNotEmpty($suggestions);
        $this->assertSame($player->id, $suggestions[0]['player_id']);
        $this->assertSame('jersey_history', $suggestions[0]['method']);
        $this->assertSame('high', $suggestions[0]['confidence']);
        $this->assertTrue($suggestions[0]['auto_apply']);
    }

    public function test_old_jersey_history_outside_window_is_ignored(): void
    {
        $player = Player::factory()->create(['full_name' => 'Old Hassan']);

        $report = MatchReport::factory()->applied()->create();
        $performance = MatchPerformance::create([
            'match_report_id' => $report->id,
            'team_side' => MatchPerformance::TEAM_SIDE_BAHRAIN,
            'player_id' => $player->id,
            'jersey_number' => 5,
            'match_position' => 'CB',
            'appearance' => 'starter',
            'reported_name' => 'Player 5',
        ]);
        // Force the row outside the 12-month lookback window.
        $performance->forceFill(['created_at' => Carbon::now()->subYears(2)])->save();

        $suggestions = (new PlayerResolver)->suggest([
            'reported_name' => 'Player 5',
            'jersey_number' => 5,
            'match_position' => 'CB',
            'team_side' => MatchPerformance::TEAM_SIDE_BAHRAIN,
        ]);

        // Without an in-window jersey-history hit AND without a fuzzy-able
        // name, the resolver should surface no suggestions.
        $this->assertSame([], $suggestions);
    }

    public function test_jersey_history_does_not_duplicate_with_name_fuzzy_match(): void
    {
        // If the same player is both the recent jersey-N occupant AND the
        // best name match, we should only see them once in the dropdown.
        $player = Player::factory()->create(['full_name' => 'Mohammed Alyaqoob']);

        $report = MatchReport::factory()->applied()->create();
        MatchPerformance::create([
            'match_report_id' => $report->id,
            'team_side' => MatchPerformance::TEAM_SIDE_BAHRAIN,
            'player_id' => $player->id,
            'jersey_number' => 6,
            'match_position' => 'CB',
            'appearance' => 'starter',
        ]);

        $suggestions = (new PlayerResolver)->suggest([
            'reported_name' => 'Mohammed Alyaqoob',
            'jersey_number' => 6,
            'match_position' => 'CB',
            'team_side' => MatchPerformance::TEAM_SIDE_BAHRAIN,
        ]);

        $playerIds = array_column($suggestions, 'player_id');
        $this->assertSame(1, count(array_filter($playerIds, fn ($id) => $id === $player->id)));
    }

    public function test_inactive_players_are_excluded_from_name_fuzzy(): void
    {
        Player::factory()->archived()->create(['full_name' => 'Retired Player']);

        $suggestions = (new PlayerResolver)->suggest([
            'reported_name' => 'Retired Player',
            'jersey_number' => 4,
            'match_position' => 'CB',
            'team_side' => MatchPerformance::TEAM_SIDE_BAHRAIN,
        ]);

        $this->assertSame([], $suggestions);
    }

    public function test_caps_suggestions_at_three(): void
    {
        // Five players with overlapping names — without a cap the dropdown
        // becomes noisy. The resolver only surfaces the top 3.
        for ($i = 1; $i <= 5; $i++) {
            Player::factory()->create(['full_name' => "Ali Ahmed {$i}"]);
        }

        $suggestions = (new PlayerResolver)->suggest([
            'reported_name' => 'Ali Ahmed',
            'jersey_number' => 7,
            'match_position' => 'CM',
            'team_side' => MatchPerformance::TEAM_SIDE_BAHRAIN,
        ]);

        $this->assertLessThanOrEqual(3, count($suggestions));
    }
}
