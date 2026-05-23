<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Jobs\GenerateNutritionistAnalysisJob;
use App\Models\NutritionistAnalysis;
use App\Models\Player;
use App\Models\PlayerRecord;
use App\Models\RecordCategory;
use App\Services\Ai\AgentRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cross-domain integration: when the Nutritionist Assistant runs for a
 * player who has recent match-performance records, those matches must
 * make it into the prompt the agent receives. This is what unlocks the
 * "low ferritin + dropping pass accuracy → late-match fatigue" type of
 * insight in the agent's output.
 */
class NutritionistMatchContextTest extends TestCase
{
    use RefreshDatabase;

    private const N8N_URL = 'https://n8n.example.test/webhook/abc';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ai.football_intel.providers.nutritionist' => 'n8n',
            'ai.football_intel.models.nutritionist' => 'opus',
            'ai.providers.n8n.url' => self::N8N_URL,
            'ai.providers.n8n.timeout' => 60,
            'ai.providers.n8n.callback_url' => '',
        ]);
    }

    public function test_prompt_includes_recent_match_performances_section(): void
    {
        $player = Player::factory()->create([
            'full_name' => 'Mohammed Alyaqoob',
            'position' => 'CB',
        ]);

        // Required medical inputs.
        PlayerRecord::factory()->bloodTest()->create([
            'player_id' => $player->id,
            'record_date' => '2026-04-01',
        ]);
        PlayerRecord::factory()->inbody()->create([
            'player_id' => $player->id,
            'record_date' => '2026-04-02',
        ]);

        // Two recent match-performance records, newest first.
        $matchPerfCategory = RecordCategory::where('slug', RecordCategory::MATCH_PERFORMANCE)
            ->firstOrFail();
        PlayerRecord::factory()->create([
            'player_id' => $player->id,
            'category_id' => $matchPerfCategory->id,
            'record_date' => '2026-05-15',
            'extracted' => [
                'opponent' => 'Saudi Arabia U19',
                'competition' => 'Friendlies',
                'stage' => 'Match 4',
                'match_position' => 'CB',
                'jersey_number' => 6,
                'appearance' => 'starter',
                'minutes_played' => 90,
                'rating' => 6.2,
                'raw_extracted' => [
                    'goals' => 0, 'assists' => 0, 'shots' => 1, 'shots_on_target' => 0,
                    'key_passes' => 1, 'passes_succeeded' => 35, 'passes_total' => 48,
                    'pass_accuracy_pct' => 72.9, 'recoveries' => 11, 'clearances' => 6,
                    'tackles_succeeded' => 1, 'tackles_attempted' => 2,
                    'aerial_duels_won' => 0, 'aerial_duels_total' => 0,
                    'ground_duels_won' => 1, 'ground_duels_total' => 2,
                    'interceptions' => 1, 'fouls_committed' => 0, 'fouls_won' => 0,
                    'yellow_cards' => 0, 'red_cards' => 0,
                    'pass_breakdown' => [
                        'by_area' => [
                            'defensive_third' => ['succeeded' => 18, 'total' => 22],
                            'middle_third' => ['succeeded' => 12, 'total' => 15],
                            'final_third' => ['succeeded' => 5, 'total' => 11],
                        ],
                    ],
                ],
            ],
        ]);
        PlayerRecord::factory()->create([
            'player_id' => $player->id,
            'category_id' => $matchPerfCategory->id,
            'record_date' => '2026-05-01',
            'extracted' => [
                'opponent' => 'Iraq U20',
                'competition' => 'AGCFF U20 Arab Gulf Cup',
                'match_position' => 'CB',
                'jersey_number' => 6,
                'appearance' => 'starter',
                'minutes_played' => 90,
                'rating' => 7.1,
                'raw_extracted' => [
                    'goals' => 0, 'assists' => 0, 'shots' => 0, 'shots_on_target' => 0,
                    'key_passes' => 0, 'passes_succeeded' => 29, 'passes_total' => 36,
                    'pass_accuracy_pct' => 80.6, 'recoveries' => 4,
                    'tackles_succeeded' => 0, 'tackles_attempted' => 2,
                    'aerial_duels_won' => 2, 'aerial_duels_total' => 2,
                ],
            ],
        ]);

        $analysis = NutritionistAnalysis::factory()->pending()->create([
            'player_id' => $player->id,
        ]);

        // n8n fake returns a minimal valid payload so the job runs to completion.
        Http::fake([
            self::N8N_URL => Http::response([[
                'code' => 0,
                'signal' => null,
                'stdout' => json_encode([
                    'summary' => 'ok',
                    'blood_analysis' => ['key_findings' => [], 'football_implications' => []],
                    'body_analysis' => ['key_findings' => [], 'football_implications' => []],
                    'match_performance_analysis' => ['key_findings' => [], 'football_implications' => []],
                    'combined_insight' => 'ok',
                    'recommendations' => [],
                    'risk_flags' => [],
                ]),
                'stderr' => '',
            ]]),
        ]);

        (new GenerateNutritionistAnalysisJob($analysis->id))
            ->handle(app(AgentRouter::class));

        // Grab the user_prompt that was sent to n8n.
        $sentPrompt = null;
        Http::recorded(function ($request) use (&$sentPrompt): void {
            $body = json_decode($request->body(), true);
            if (is_array($body) && isset($body['user_prompt'])) {
                $sentPrompt = $body['user_prompt'];
            }
        });

        $this->assertNotNull($sentPrompt, 'No HTTP call captured');
        $this->assertStringContainsString('RECENT MATCH PERFORMANCES', $sentPrompt);
        // Newest first — Saudi match (2026-05-15) before Iraq match (2026-05-01).
        $saudiPos = strpos($sentPrompt, 'Saudi Arabia U19');
        $iraqPos = strpos($sentPrompt, 'Iraq U20');
        $this->assertNotFalse($saudiPos, 'Saudi match missing from prompt');
        $this->assertNotFalse($iraqPos, 'Iraq match missing from prompt');
        $this->assertLessThan($iraqPos, $saudiPos, 'Match order should be newest-first');

        // Pass breakdown was included for the Saudi match.
        $this->assertStringContainsString('Pass profile:', $sentPrompt);
        $this->assertStringContainsString('area 18/22 12/15 5/11', $sentPrompt);

        // Headline stats made it through.
        $this->assertStringContainsString('rating 6.2', $sentPrompt);
        $this->assertStringContainsString('rating 7.1', $sentPrompt);
        $this->assertStringContainsString('35/48 passes (72.9%)', $sentPrompt);
    }

    public function test_prompt_falls_back_gracefully_when_no_match_performances(): void
    {
        $player = Player::factory()->create();
        PlayerRecord::factory()->bloodTest()->create(['player_id' => $player->id]);
        PlayerRecord::factory()->inbody()->create(['player_id' => $player->id]);
        // No match-performance records.

        $analysis = NutritionistAnalysis::factory()->pending()->create([
            'player_id' => $player->id,
        ]);

        Http::fake([
            self::N8N_URL => Http::response([[
                'code' => 0,
                'signal' => null,
                'stdout' => json_encode([
                    'summary' => 'ok',
                    'blood_analysis' => ['key_findings' => [], 'football_implications' => []],
                    'body_analysis' => ['key_findings' => [], 'football_implications' => []],
                    'match_performance_analysis' => ['key_findings' => [], 'football_implications' => []],
                    'combined_insight' => 'ok',
                    'recommendations' => [],
                    'risk_flags' => [],
                ]),
                'stderr' => '',
            ]]),
        ]);

        (new GenerateNutritionistAnalysisJob($analysis->id))
            ->handle(app(AgentRouter::class));

        $sentPrompt = null;
        Http::recorded(function ($request) use (&$sentPrompt): void {
            $body = json_decode($request->body(), true);
            if (is_array($body) && isset($body['user_prompt'])) {
                $sentPrompt = $body['user_prompt'];
            }
        });

        $this->assertNotNull($sentPrompt);
        $this->assertStringContainsString('RECENT MATCH PERFORMANCES', $sentPrompt);
        $this->assertStringContainsString('No recent match performances on file', $sentPrompt);
    }
}
