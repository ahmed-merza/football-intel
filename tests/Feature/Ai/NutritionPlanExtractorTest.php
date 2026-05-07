<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\Agents\NutritionPlanExtractor;
use App\Models\Player;
use App\Models\PlayerRecord;
use App\Models\RecordMetric;
use App\Services\Medical\RecordMetricFanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Tests\TestCase;

class NutritionPlanExtractorTest extends TestCase
{
    use RefreshDatabase;

    public function test_extractor_returns_typed_plan_from_canned_response(): void
    {
        NutritionPlanExtractor::fake([
            [
                'plan_name' => 'Right Calories Performance Plan',
                'patient_name_on_report' => 'Kameel Al-Sabbagh',
                'plan_start_date' => '2026-02-01',
                'plan_end_date' => '2026-04-30',
                'metrics' => [
                    ['name' => 'Daily energy',  'key' => 'daily_kcal',      'value' => 3000, 'unit' => 'kcal', 'ref_low' => null, 'ref_high' => null, 'flag' => 'normal'],
                    ['name' => 'Total protein', 'key' => 'daily_protein_g', 'value' => 160,  'unit' => 'g',    'ref_low' => null, 'ref_high' => null, 'flag' => 'normal'],
                    ['name' => 'Hydration',     'key' => 'daily_water_l',   'value' => 4.0,  'unit' => 'L',    'ref_low' => null, 'ref_high' => null, 'flag' => 'normal'],
                ],
                'meals' => [
                    ['name' => 'Breakfast', 'time' => '07:30', 'items' => [
                        ['food' => 'Oats', 'grams' => 80, 'protein_g' => 11, 'carb_g' => 56, 'fat_g' => 6, 'kcal' => 320],
                    ]],
                ],
                'supplements' => [
                    ['name' => 'Whey isolate', 'dose' => '30 g', 'timing' => 'post-training', 'purpose' => 'recovery'],
                ],
                'notes' => 'Hydration goal applies to non-training days too.',
            ],
        ]);

        /** @var StructuredAgentResponse $response */
        $response = (new NutritionPlanExtractor)->prompt('Plan: 3000 kcal, 160g protein, 4 L water.');
        $result = $response->toArray();

        $this->assertSame('Right Calories Performance Plan', $result['plan_name']);
        $this->assertCount(3, $result['metrics']);
        $this->assertSame('daily_protein_g', $result['metrics'][1]['key']);
        $this->assertSame('Whey isolate', $result['supplements'][0]['name']);
    }

    public function test_registered_keys_include_the_canonical_set(): void
    {
        $keys = NutritionPlanExtractor::registeredKeys();

        foreach (['daily_kcal', 'daily_protein_g', 'daily_carb_g', 'daily_fat_g', 'daily_water_l'] as $expected) {
            $this->assertContains($expected, $keys, "missing canonical key {$expected}");
        }
    }

    public function test_metric_fanner_writes_one_record_metric_per_keyed_target(): void
    {
        $player = Player::factory()->create();
        $record = PlayerRecord::factory()->nutritionPlan()->create([
            'player_id' => $player->id,
            'extracted' => [
                'metrics' => [
                    ['name' => 'Daily energy',  'key' => 'daily_kcal',      'value' => 3000, 'unit' => 'kcal', 'flag' => 'normal'],
                    ['name' => 'Total protein', 'key' => 'daily_protein_g', 'value' => 160,  'unit' => 'g',    'flag' => 'normal'],
                    // Unmapped target — should be skipped
                    ['name' => 'Caffeine',      'key' => 'other',           'value' => 200,  'unit' => 'mg',   'flag' => 'normal'],
                    // Missing value — also skipped
                    ['name' => 'Broken row',    'key' => 'daily_water_l',   'unit' => 'L',                     'flag' => 'normal'],
                ],
            ],
        ]);

        $written = app(RecordMetricFanner::class)->fan($record);

        $this->assertSame(2, $written);
        $this->assertSame(2, RecordMetric::where('record_id', $record->id)->count());
        $this->assertSame(
            3000.0,
            (float) RecordMetric::where('record_id', $record->id)->where('metric_key', 'daily_kcal')->value('metric_value'),
        );
    }

    public function test_nutrition_plan_factory_state_produces_a_fan_able_payload(): void
    {
        $player = Player::factory()->create();
        $record = PlayerRecord::factory()->nutritionPlan()->create(['player_id' => $player->id]);

        $written = app(RecordMetricFanner::class)->fan($record);

        $this->assertGreaterThanOrEqual(5, $written, 'Default nutrition-plan factory should produce ≥5 keyed targets.');
        $this->assertSame($written, RecordMetric::where('record_id', $record->id)->count());
    }
}
