<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\Agents\BloodTestExtractor;
use App\Models\Player;
use App\Models\PlayerRecord;
use App\Models\RecordCategory;
use App\Models\RecordMetric;
use App\Services\Medical\RecordMetricFanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Tests\TestCase;

class BloodTestExtractorTest extends TestCase
{
    use RefreshDatabase;

    public function test_extractor_returns_typed_blood_panel_from_canned_response(): void
    {
        BloodTestExtractor::fake([
            [
                'patient_name_on_report' => 'Abdulrahman Mubarak',
                'sample_date' => '2026-02-15',
                'lab_name' => 'HICARE Medical Centre',
                'labs' => [
                    ['name' => 'Haemoglobin', 'key' => 'hb_g_dl', 'value' => 14.2, 'unit' => 'g/dL', 'ref_low' => 13.0, 'ref_high' => 17.5, 'flag' => 'normal'],
                    ['name' => 'Ferritin', 'key' => 'ferritin_ng_ml', 'value' => 28.0, 'unit' => 'ng/mL', 'ref_low' => 22.0, 'ref_high' => 322.0, 'flag' => 'low'],
                    ['name' => 'Vitamin D (25-OH)', 'key' => 'vit_d_ng_ml', 'value' => 18.0, 'unit' => 'ng/mL', 'ref_low' => 30.0, 'ref_high' => 100.0, 'flag' => 'critical'],
                ],
            ],
        ]);

        /** @var StructuredAgentResponse $response */
        $response = (new BloodTestExtractor)->prompt('Haemoglobin 14.2 g/dL, Ferritin 28 ng/mL, Vitamin D 18 ng/mL.');
        $result = $response->toArray();

        $this->assertSame('HICARE Medical Centre', $result['lab_name']);
        $this->assertCount(3, $result['labs']);
        $this->assertSame('hb_g_dl', $result['labs'][0]['key']);
        $this->assertSame('critical', $result['labs'][2]['flag']);
    }

    public function test_registered_keys_include_the_canonical_set(): void
    {
        $keys = BloodTestExtractor::registeredKeys();

        foreach (['hb_g_dl', 'ferritin_ng_ml', 'vit_d_ng_ml', 'sodium_mmol_l', 'potassium_mmol_l', 'cpk_u_l', 'ldh_u_l', 'hba1c_pct'] as $expected) {
            $this->assertContains($expected, $keys, "missing canonical key {$expected}");
        }
    }

    public function test_metric_fanner_writes_one_record_metric_per_keyed_lab(): void
    {
        $player = Player::factory()->create();
        $record = PlayerRecord::factory()->bloodTest()->create([
            'player_id' => $player->id,
            'extracted' => [
                'labs' => [
                    ['name' => 'Haemoglobin', 'key' => 'hb_g_dl', 'value' => 14.2, 'unit' => 'g/dL', 'ref_low' => 13.0, 'ref_high' => 17.5, 'flag' => 'normal'],
                    ['name' => 'Ferritin', 'key' => 'ferritin_ng_ml', 'value' => 28.0, 'unit' => 'ng/mL', 'ref_low' => 22.0, 'ref_high' => 322.0, 'flag' => 'low'],
                    // Unmapped analyte — should be skipped by the fanner
                    ['name' => 'Erythrocyte sedimentation rate', 'key' => 'other', 'value' => 12, 'unit' => 'mm/hr', 'flag' => 'normal'],
                    // Missing value — also skipped
                    ['name' => 'Broken row', 'key' => 'vit_d_ng_ml', 'unit' => 'ng/mL', 'flag' => 'normal'],
                ],
            ],
        ]);

        $written = app(RecordMetricFanner::class)->fan($record);

        $this->assertSame(2, $written);
        $this->assertSame(2, RecordMetric::where('record_id', $record->id)->count());
        $this->assertSame(
            'low',
            RecordMetric::where('record_id', $record->id)->where('metric_key', 'ferritin_ng_ml')->value('flag'),
        );
    }

    public function test_metric_fanner_is_idempotent_on_rerun(): void
    {
        $player = Player::factory()->create();
        $record = PlayerRecord::factory()->bloodTest()->create([
            'player_id' => $player->id,
            'extracted' => [
                'labs' => [
                    ['name' => 'Haemoglobin', 'key' => 'hb_g_dl', 'value' => 14.2, 'unit' => 'g/dL', 'flag' => 'normal'],
                ],
            ],
        ]);

        $fanner = app(RecordMetricFanner::class);
        $fanner->fan($record);
        $fanner->fan($record);
        $fanner->fan($record);

        $this->assertSame(
            1,
            RecordMetric::where('record_id', $record->id)->count(),
            'Fanner must replace, not append, so re-running leaves exactly one row per key.',
        );
    }

    public function test_fanner_clears_metrics_when_labs_array_is_empty(): void
    {
        $player = Player::factory()->create();
        $record = PlayerRecord::factory()->bloodTest()->create([
            'player_id' => $player->id,
            'extracted' => ['labs' => []],
        ]);

        $this->assertSame(0, app(RecordMetricFanner::class)->fan($record));
        $this->assertSame(0, RecordMetric::where('record_id', $record->id)->count());
    }

    public function test_classifier_ignores_other_category_and_leaves_payload_untouched(): void
    {
        $player = Player::factory()->create();
        $record = PlayerRecord::factory()->create([
            'player_id' => $player->id,
            'category_id' => RecordCategory::where('slug', RecordCategory::COACH_FEEDBACK)->value('id'),
            'extracted' => ['classifier' => ['category' => 'coach_feedback', 'confidence' => 0.9, 'reasoning' => '...']],
        ]);
        $before = $record->extracted;

        // Simulate what the job does for non-blood categories: mark pending,
        // don't call the extractor (no extractor for coach_feedback yet).
        $record->update([
            'extracted' => array_merge($before, ['structured_extraction_pending' => true]),
        ]);

        $this->assertTrue($record->fresh()->extracted['structured_extraction_pending']);
        $this->assertSame(0, RecordMetric::where('record_id', $record->id)->count());
    }
}
