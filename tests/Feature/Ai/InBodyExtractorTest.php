<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\Agents\InBodyExtractor;
use App\Models\Player;
use App\Models\PlayerRecord;
use App\Models\RecordMetric;
use App\Services\Medical\RecordMetricFanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Tests\TestCase;

class InBodyExtractorTest extends TestCase
{
    use RefreshDatabase;

    public function test_extractor_returns_typed_body_comp_from_canned_response(): void
    {
        InBodyExtractor::fake([
            [
                'patient_name_on_report' => 'Abdulrahman Mubarak',
                'test_date' => '2026-02-15',
                'device' => 'InBody 770',
                'metrics' => [
                    ['name' => 'Weight', 'key' => 'weight_kg', 'value' => 75.4, 'unit' => 'kg', 'ref_low' => 60.0, 'ref_high' => 95.0, 'flag' => 'normal'],
                    ['name' => 'Percent Body Fat', 'key' => 'body_fat_pct', 'value' => 22.1, 'unit' => '%', 'ref_low' => 10.0, 'ref_high' => 20.0, 'flag' => 'high'],
                    ['name' => 'Skeletal Muscle Mass', 'key' => 'skeletal_muscle_kg', 'value' => 32.5, 'unit' => 'kg', 'ref_low' => 30.0, 'ref_high' => 40.0, 'flag' => 'normal'],
                ],
                'segmental' => [
                    'right_arm_kg' => 3.4, 'left_arm_kg' => 3.3,
                    'trunk_kg' => 26.0,
                    'right_leg_kg' => 9.8, 'left_leg_kg' => 9.7,
                ],
            ],
        ]);

        /** @var StructuredAgentResponse $response */
        $response = (new InBodyExtractor)->prompt('Weight 75.4 kg, BF% 22.1, SMM 32.5 kg.');
        $result = $response->toArray();

        $this->assertSame('InBody 770', $result['device']);
        $this->assertCount(3, $result['metrics']);
        $this->assertSame('body_fat_pct', $result['metrics'][1]['key']);
        $this->assertSame('high', $result['metrics'][1]['flag']);
        $this->assertSame(26.0, $result['segmental']['trunk_kg']);
    }

    public function test_registered_keys_include_the_canonical_set(): void
    {
        $keys = InBodyExtractor::registeredKeys();

        foreach (['weight_kg', 'body_fat_pct', 'body_fat_kg', 'skeletal_muscle_kg', 'visceral_fat', 'phase_angle', 'tbw_l', 'icw_l', 'ecw_l'] as $expected) {
            $this->assertContains($expected, $keys, "missing canonical key {$expected}");
        }
    }

    public function test_metric_fanner_writes_one_record_metric_per_keyed_inbody_row(): void
    {
        $player = Player::factory()->create();
        $record = PlayerRecord::factory()->inbody()->create([
            'player_id' => $player->id,
            'extracted' => [
                'metrics' => [
                    ['name' => 'Weight',                'key' => 'weight_kg',          'value' => 75.4, 'unit' => 'kg', 'ref_low' => 60.0, 'ref_high' => 95.0, 'flag' => 'normal'],
                    ['name' => 'Percent Body Fat',      'key' => 'body_fat_pct',       'value' => 22.1, 'unit' => '%',  'ref_low' => 10.0, 'ref_high' => 20.0, 'flag' => 'high'],
                    // Unmapped metric — should be skipped by the fanner
                    ['name' => 'Bone Mineral Content',  'key' => 'other',              'value' => 3.2,  'unit' => 'kg',                                          'flag' => 'normal'],
                    // Missing value — also skipped
                    ['name' => 'Broken row',            'key' => 'visceral_fat',       'unit' => 'lvl',                                                          'flag' => 'normal'],
                ],
            ],
        ]);

        $written = app(RecordMetricFanner::class)->fan($record);

        $this->assertSame(2, $written);
        $this->assertSame(2, RecordMetric::where('record_id', $record->id)->count());
        $this->assertSame(
            'high',
            RecordMetric::where('record_id', $record->id)->where('metric_key', 'body_fat_pct')->value('flag'),
        );
    }

    public function test_inbody_factory_state_produces_a_fan_able_payload(): void
    {
        $player = Player::factory()->create();
        $record = PlayerRecord::factory()->inbody()->create(['player_id' => $player->id]);

        $written = app(RecordMetricFanner::class)->fan($record);

        $this->assertGreaterThanOrEqual(5, $written, 'Default inbody factory should produce ≥5 keyed metrics.');
        $this->assertSame($written, RecordMetric::where('record_id', $record->id)->count());
    }
}
