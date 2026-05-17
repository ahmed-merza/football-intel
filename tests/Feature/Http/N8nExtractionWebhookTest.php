<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\NutritionistAnalysis;
use App\Models\PendingExtraction;
use App\Models\Player;
use App\Models\PlayerRecord;
use App\Models\RecordCategory;
use App\Models\RecordMetric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class N8nExtractionWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret-123';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ai.providers.n8n.callback_secret' => self::SECRET,
        ]);
    }

    public function test_rejects_callback_without_matching_secret(): void
    {
        $this->postJson('/webhooks/n8n/extraction', [
            'correlation_id' => (string) Str::uuid(),
            'result' => [['code' => 0, 'stdout' => '{}', 'stderr' => '']],
        ], ['X-Callback-Secret' => 'wrong'])
            ->assertStatus(401);
    }

    public function test_rejects_callback_when_no_secret_is_configured(): void
    {
        // Misconfiguration is the dangerous failure mode — we close
        // rather than open. Empty config secret should reject every
        // call, even ones that send an empty header.
        config(['ai.providers.n8n.callback_secret' => '']);

        $this->postJson('/webhooks/n8n/extraction', [
            'correlation_id' => (string) Str::uuid(),
            'result' => [['code' => 0, 'stdout' => '{}', 'stderr' => '']],
        ], ['X-Callback-Secret' => ''])
            ->assertStatus(401);
    }

    public function test_unknown_correlation_returns_200_so_n8n_does_not_retry(): void
    {
        $this->postJson('/webhooks/n8n/extraction', [
            'correlation_id' => (string) Str::uuid(),
            'result' => [['code' => 0, 'stdout' => '{}', 'stderr' => '']],
        ], ['X-Callback-Secret' => self::SECRET])
            ->assertOk()
            ->assertJson(['status' => 'unknown_correlation']);
    }

    public function test_already_completed_correlation_is_a_no_op(): void
    {
        $pending = PendingExtraction::create([
            'correlation_id' => (string) Str::uuid(),
            'kind' => PendingExtraction::KIND_BLOOD_TEST,
            'status' => PendingExtraction::STATUS_COMPLETED,
            'expires_at' => Carbon::now()->addMinutes(15),
            'processed_at' => Carbon::now(),
        ]);

        $this->postJson('/webhooks/n8n/extraction', [
            'correlation_id' => $pending->correlation_id,
            'result' => [['code' => 0, 'stdout' => '{}', 'stderr' => '']],
        ], ['X-Callback-Secret' => self::SECRET])
            ->assertOk()
            ->assertJson(['status' => 'noop_completed']);
    }

    public function test_blood_test_callback_applies_payload_and_fans_metrics(): void
    {
        $player = Player::factory()->create();
        $record = PlayerRecord::factory()->bloodTest()->create([
            'player_id' => $player->id,
            'extracted' => ['classifier' => ['category' => RecordCategory::BLOOD_TEST]],
        ]);

        $pending = PendingExtraction::create([
            'correlation_id' => (string) Str::uuid(),
            'record_id' => $record->id,
            'kind' => PendingExtraction::KIND_BLOOD_TEST,
            'status' => PendingExtraction::STATUS_PENDING,
            'request' => ['has_schema' => true],
            'expires_at' => Carbon::now()->addMinutes(15),
        ]);

        $extractedJson = json_encode([
            'patient_name_on_report' => 'Test Player',
            'sample_date' => '2026-04-01',
            'lab_name' => 'HICARE Medical Centre',
            'labs' => [
                ['name' => 'Haemoglobin', 'key' => 'hb_g_dl', 'value' => 14.2, 'unit' => 'g/dL', 'flag' => 'normal'],
                ['name' => 'Vitamin D',   'key' => 'vit_d_ng_ml', 'value' => 18.0, 'unit' => 'ng/mL', 'flag' => 'critical'],
            ],
        ]);

        $this->postJson('/webhooks/n8n/extraction', [
            'correlation_id' => $pending->correlation_id,
            'result' => [['code' => 0, 'stdout' => $extractedJson, 'stderr' => '']],
        ], ['X-Callback-Secret' => self::SECRET])
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        $record->refresh();
        $this->assertSame('Test Player', $record->extracted['patient_name_on_report']);
        $this->assertSame('HICARE Medical Centre', $record->source_lab);
        $this->assertCount(2, $record->extracted['labs']);

        // Fanner ran — record_metrics has the keyed rows.
        $this->assertSame(2, RecordMetric::where('record_id', $record->id)->count());
        $this->assertSame(
            'critical',
            RecordMetric::where('record_id', $record->id)
                ->where('metric_key', 'vit_d_ng_ml')
                ->value('flag'),
        );

        // Pending row closed.
        $pending->refresh();
        $this->assertSame(PendingExtraction::STATUS_COMPLETED, $pending->status);
        $this->assertNotNull($pending->processed_at);
    }

    public function test_inbody_callback_applies_payload(): void
    {
        $player = Player::factory()->create();
        $record = PlayerRecord::factory()->inbody()->create(['player_id' => $player->id]);

        $pending = PendingExtraction::create([
            'correlation_id' => (string) Str::uuid(),
            'record_id' => $record->id,
            'kind' => PendingExtraction::KIND_INBODY,
            'status' => PendingExtraction::STATUS_PENDING,
            'request' => ['has_schema' => true],
            'expires_at' => Carbon::now()->addMinutes(15),
        ]);

        $extractedJson = json_encode([
            'patient_name_on_report' => 'Test Player',
            'test_date' => '2026-04-01',
            'device' => 'InBody 770',
            'metrics' => [
                ['name' => 'Weight', 'key' => 'weight_kg', 'value' => 75.4, 'unit' => 'kg', 'flag' => 'normal'],
                ['name' => 'Body Fat %', 'key' => 'body_fat_pct', 'value' => 22.1, 'unit' => '%', 'flag' => 'high'],
            ],
        ]);

        $this->postJson('/webhooks/n8n/extraction', [
            'correlation_id' => $pending->correlation_id,
            'result' => [['code' => 0, 'stdout' => $extractedJson, 'stderr' => '']],
        ], ['X-Callback-Secret' => self::SECRET])
            ->assertOk();

        $record->refresh();
        $this->assertSame('InBody 770', $record->source_lab);
        $this->assertSame(2, RecordMetric::where('record_id', $record->id)->count());
    }

    public function test_nutritionist_analysis_callback_promotes_pending_analysis_to_completed(): void
    {
        $analysis = NutritionistAnalysis::factory()->pending()->create();

        $pending = PendingExtraction::create([
            'correlation_id' => (string) Str::uuid(),
            'analysis_id' => $analysis->id,
            'kind' => PendingExtraction::KIND_NUTRITIONIST_ANALYSIS,
            'status' => PendingExtraction::STATUS_PENDING,
            'request' => ['has_schema' => true],
            'expires_at' => Carbon::now()->addMinutes(15),
        ]);

        $payload = [
            'summary' => 'Iron borderline, body composition strong.',
            'blood_analysis' => ['key_findings' => ['Ferritin 33 ng/mL']],
            'body_analysis' => ['key_findings' => ['BF 11.4%']],
            'combined_insight' => 'Pre-emptive iron supplementation justified.',
            'recommendations' => [['area' => 'supplement', 'action' => 'Iron bisglycinate 25mg']],
            'risk_flags' => [],
        ];

        $this->postJson('/webhooks/n8n/extraction', [
            'correlation_id' => $pending->correlation_id,
            'result' => [['code' => 0, 'stdout' => json_encode($payload), 'stderr' => '']],
        ], ['X-Callback-Secret' => self::SECRET])
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        $analysis->refresh();
        $this->assertSame(NutritionistAnalysis::STATUS_COMPLETED, $analysis->status);
        $this->assertSame('Iron borderline, body composition strong.', $analysis->summary_text);
        // jsonb normalises key ordering, so compare canonically.
        $this->assertEqualsCanonicalizing($payload, $analysis->payload);
        $this->assertNotNull($analysis->generated_at);

        $pending->refresh();
        $this->assertSame(PendingExtraction::STATUS_COMPLETED, $pending->status);
    }

    public function test_nutritionist_analysis_callback_skips_when_analysis_already_terminal(): void
    {
        // Sync path beat the callback to the punch — the analysis is
        // already completed. Callback must not clobber the fresher state.
        $analysis = NutritionistAnalysis::factory()->create([
            'status' => NutritionistAnalysis::STATUS_COMPLETED,
            'summary_text' => 'Sync-path summary',
        ]);

        $pending = PendingExtraction::create([
            'correlation_id' => (string) Str::uuid(),
            'analysis_id' => $analysis->id,
            'kind' => PendingExtraction::KIND_NUTRITIONIST_ANALYSIS,
            'status' => PendingExtraction::STATUS_PENDING,
            'request' => ['has_schema' => true],
            'expires_at' => Carbon::now()->addMinutes(15),
        ]);

        $this->postJson('/webhooks/n8n/extraction', [
            'correlation_id' => $pending->correlation_id,
            'result' => [['code' => 0, 'stdout' => '{"summary": "Late callback"}', 'stderr' => '']],
        ], ['X-Callback-Secret' => self::SECRET])
            ->assertOk();

        $this->assertSame('Sync-path summary', $analysis->fresh()->summary_text);
    }

    public function test_malformed_result_marks_pending_failed(): void
    {
        $pending = PendingExtraction::create([
            'correlation_id' => (string) Str::uuid(),
            'kind' => PendingExtraction::KIND_BLOOD_TEST,
            'status' => PendingExtraction::STATUS_PENDING,
            'request' => ['has_schema' => true],
            'expires_at' => Carbon::now()->addMinutes(15),
        ]);

        $this->postJson('/webhooks/n8n/extraction', [
            'correlation_id' => $pending->correlation_id,
            'result' => [['code' => 1, 'stdout' => '', 'stderr' => 'claude died']],
        ], ['X-Callback-Secret' => self::SECRET])
            ->assertStatus(422);

        $pending->refresh();
        $this->assertSame(PendingExtraction::STATUS_FAILED, $pending->status);
        $this->assertStringContainsString('claude', (string) $pending->error);
    }
}
