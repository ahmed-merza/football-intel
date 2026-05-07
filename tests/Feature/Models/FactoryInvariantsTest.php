<?php

declare(strict_types=1);

use App\Models\Alert;
use App\Models\Attachment;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Player;
use App\Models\PlayerNote;
use App\Models\PlayerRecord;
use App\Models\RecordCategory;
use App\Models\RecordMetric;
use App\Models\Report;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('builds a plausible player', function (): void {
    $player = Player::factory()->create();

    expect($player->full_name)->not->toBeEmpty()
        ->and($player->status)->toBe(Player::STATUS_ACTIVE)
        ->and($player->player_code)->toStartWith('SC-')
        ->and($player->phone)->toStartWith('+973')
        ->and($player->nationality)->toBe('Bahraini');
});

it('supports the three archetype player states', function (): void {
    $gk = Player::factory()->goalkeeper()->create();
    $inactive = Player::factory()->inactive()->create();
    $archived = Player::factory()->archived()->create();

    expect($gk->position)->toBe('GK')
        ->and($gk->height_cm)->toBeGreaterThanOrEqual(185)
        ->and($inactive->status)->toBe(Player::STATUS_INACTIVE)
        ->and($archived->status)->toBe(Player::STATUS_ARCHIVED);
});

it('wires submission → attachment → record → metric through factories', function (): void {
    $submission = Submission::factory()->create();
    $attachment = Attachment::factory()->create(['submission_id' => $submission->id]);
    $record = PlayerRecord::factory()->bloodTest()->create([
        'submission_id' => $submission->id,
        'primary_attachment_id' => $attachment->id,
    ]);
    $metric = RecordMetric::factory()->ferritin(25.0)->create([
        'record_id' => $record->id,
        'player_id' => $record->player_id,
        'category_id' => $record->category_id,
        'record_date' => $record->record_date,
    ]);

    expect($submission->attachments)->toHaveCount(1)
        ->and($attachment->submission->is($submission))->toBeTrue()
        ->and($record->submission->is($submission))->toBeTrue()
        ->and($record->primaryAttachment->is($attachment))->toBeTrue()
        ->and($metric->record->is($record))->toBeTrue()
        ->and($metric->flag)->toBe(RecordMetric::FLAG_LOW);
});

it('produces category-specific extracted payloads', function (): void {
    $blood = PlayerRecord::factory()->bloodTest()->create();
    $inbody = PlayerRecord::factory()->inbody()->create();
    $gps = PlayerRecord::factory()->gpsWearable()->create();
    $nutrition = PlayerRecord::factory()->nutritionPlan()->create();

    /** @var list<array{key: string}> $inbodyMetrics */
    $inbodyMetrics = $inbody->extracted['metrics'];
    $inbodyKeys = array_column($inbodyMetrics, 'key');

    /** @var list<array{key: string}> $nutritionMetrics */
    $nutritionMetrics = $nutrition->extracted['metrics'];
    $nutritionKeys = array_column($nutritionMetrics, 'key');

    expect($blood->extracted)->toHaveKeys(['sample_date', 'lab_name', 'labs'])
        ->and($blood->extracted['labs'])->not->toBeEmpty()
        ->and($inbody->extracted)->toHaveKeys(['test_date', 'device', 'metrics', 'segmental'])
        ->and($inbodyMetrics)->not->toBeEmpty()
        ->and($inbodyKeys)->toContain('weight_kg', 'body_fat_pct', 'skeletal_muscle_kg')
        ->and($gps->extracted)->toHaveKeys(['device', 'session_type', 'total_distance_m'])
        ->and($nutrition->extracted)->toHaveKeys(['plan_name', 'metrics', 'meals', 'supplements'])
        ->and($nutritionKeys)->toContain('daily_kcal', 'daily_protein_g', 'daily_water_l');
});

it('encrypts attachment extracted_text at the model layer while leaving it readable', function (): void {
    $attachment = Attachment::factory()->create([
        'extracted_text' => 'Confidential blood test text content.',
    ]);

    // Model reads the decrypted value
    expect($attachment->fresh()->extracted_text)->toBe('Confidential blood test text content.');

    // Raw DB row holds an encrypted blob — never plaintext
    $raw = DB::table('attachments')->where('id', $attachment->id)->value('extracted_text');
    expect($raw)->not->toBe('Confidential blood test text content.');
});

it('keeps record_metrics jsonb-free and queryable by key', function (): void {
    $player = Player::factory()->create();
    $record = PlayerRecord::factory()->bloodTest()->create(['player_id' => $player->id]);
    RecordMetric::factory()->ferritin(15.0)->create([
        'record_id' => $record->id,
        'player_id' => $player->id,
        'record_date' => $record->record_date,
    ]);
    RecordMetric::factory()->vitaminD(18.0)->create([
        'record_id' => $record->id,
        'player_id' => $player->id,
        'record_date' => $record->record_date,
    ]);

    expect(RecordMetric::where('metric_key', 'ferritin_ng_ml')->value('flag'))
        ->toBe(RecordMetric::FLAG_LOW)
        ->and(RecordMetric::where('metric_key', 'vit_d_ng_ml')->value('flag'))
        ->toBe(RecordMetric::FLAG_CRITICAL);
});

it('creates plausible alerts, notes, KB docs, and reports', function (): void {
    $alert = Alert::factory()->critical()->create();
    $note = PlayerNote::factory()->create();
    $doc = KnowledgeDocument::factory()->supplementGuide()->create();
    $chunk = KnowledgeChunk::factory()->create(['document_id' => $doc->id]);
    $playerReport = Report::factory()->create();
    $teamReport = Report::factory()->team()->create();

    expect($alert->severity)->toBe(Alert::SEVERITY_CRITICAL)
        ->and($alert->acknowledged_at)->toBeNull()
        ->and($note->body)->not->toBeEmpty()
        ->and($doc->source_type)->toBe(KnowledgeDocument::SOURCE_SUPPLEMENT_GUIDE)
        ->and($chunk->document->is($doc))->toBeTrue()
        ->and($playerReport->type)->toBe(Report::TYPE_PLAYER)
        ->and($teamReport->type)->toBe(Report::TYPE_TEAM)
        ->and($teamReport->player_id)->toBeNull();
});

it('resolves RecordCategory constants to the seeded rows', function (): void {
    $slugs = [
        RecordCategory::BLOOD_TEST, RecordCategory::INBODY, RecordCategory::GPS_WEARABLE,
        RecordCategory::NUTRITION_PLAN, RecordCategory::HYDRATION_SUPPLEMENT_PLAN,
        RecordCategory::COACH_FEEDBACK, RecordCategory::MATCH_ACTIVITY, RecordCategory::OTHER,
    ];

    foreach ($slugs as $slug) {
        expect(RecordCategory::where('slug', $slug)->exists())->toBeTrue("missing category {$slug}");
    }
});
