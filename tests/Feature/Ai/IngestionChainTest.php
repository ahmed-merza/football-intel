<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\Agents\BloodTestExtractor;
use App\Ai\Agents\DocumentClassifier;
use App\Jobs\ClassifyAttachmentJob;
use App\Jobs\ExtractTextFromAttachmentJob;
use App\Models\Attachment;
use App\Models\Player;
use App\Models\PlayerRecord;
use App\Models\RecordCategory;
use App\Models\RecordMetric;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\MakePdf;
use Tests\TestCase;

class IngestionChainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_upload_chains_extract_classify_and_structured_extraction(): void
    {
        DocumentClassifier::fake([
            [
                'category' => RecordCategory::BLOOD_TEST,
                'confidence' => 0.94,
                'reasoning' => 'Contains Haemoglobin + ferritin with units + reference ranges.',
            ],
        ]);
        BloodTestExtractor::fake([
            [
                'patient_name_on_report' => 'Test Player',
                'sample_date' => '2026-02-15',
                'lab_name' => 'HICARE Medical Centre',
                'labs' => [
                    ['name' => 'Haemoglobin', 'key' => 'hb_g_dl', 'value' => 14.2, 'unit' => 'g/dL', 'ref_low' => 13.0, 'ref_high' => 17.5, 'flag' => 'normal'],
                    ['name' => 'Ferritin', 'key' => 'ferritin_ng_ml', 'value' => 33.0, 'unit' => 'ng/mL', 'ref_low' => 22.0, 'ref_high' => 322.0, 'flag' => 'normal'],
                    ['name' => 'Vitamin D', 'key' => 'vit_d_ng_ml', 'value' => 28.0, 'unit' => 'ng/mL', 'ref_low' => 30.0, 'ref_high' => 100.0, 'flag' => 'low'],
                ],
            ],
        ]);

        $admin = User::factory()->create();
        $player = Player::factory()->create();

        $pdf = UploadedFile::fake()->createWithContent(
            'blood.pdf',
            MakePdf::withText('Haemoglobin 14.2 g/dL. Ferritin 33 ng/mL. Vitamin D 28 ng/mL.'),
        );

        $this->actingAs($admin)
            ->post("/players/{$player->id}/submissions", ['files' => [$pdf]])
            ->assertRedirect();

        $submission = Submission::firstOrFail();
        $attachment = Attachment::firstOrFail();

        $this->assertTrue($attachment->refresh()->is_text_pdf);
        $this->assertStringContainsString('Haemoglobin', $attachment->extracted_text);

        $record = PlayerRecord::firstOrFail();
        $this->assertSame($player->id, $record->player_id);
        $this->assertSame(
            RecordCategory::where('slug', RecordCategory::BLOOD_TEST)->value('id'),
            $record->category_id,
        );

        // Structured payload replaced the classifier-minimal stub
        $this->assertSame('HICARE Medical Centre', $record->source_lab);
        $this->assertSame('HICARE Medical Centre', $record->extracted['lab_name']);
        $this->assertCount(3, $record->extracted['labs']);
        $this->assertSame('2026-02-15', $record->record_date->toDateString());
        $this->assertStringContainsString('flagged', $record->summary_text ?? '');

        // Classifier breadcrumb preserved for audit
        $this->assertSame(0.94, $record->extracted['classifier']['confidence']);

        // Metric rows fanned out
        $this->assertSame(3, RecordMetric::where('record_id', $record->id)->count());
        $this->assertSame(
            'low',
            RecordMetric::where('record_id', $record->id)->where('metric_key', 'vit_d_ng_ml')->value('flag'),
        );

        $this->assertSame(Submission::STATUS_CLASSIFIED, $submission->refresh()->status);
    }

    public function test_low_confidence_classification_skips_structured_extraction(): void
    {
        DocumentClassifier::fake([
            [
                'category' => RecordCategory::BLOOD_TEST,
                'confidence' => 0.45,
                'reasoning' => 'Uncertain.',
            ],
        ]);
        // Extractor must never be called when confidence is below threshold.
        BloodTestExtractor::fake()->preventStrayPrompts();

        $admin = User::factory()->create();
        $player = Player::factory()->create();

        $pdf = UploadedFile::fake()->createWithContent(
            'ambiguous.pdf',
            MakePdf::withText('Some blurry numbers maybe Hb 14.'),
        );

        $this->actingAs($admin)
            ->post("/players/{$player->id}/submissions", ['files' => [$pdf]])
            ->assertRedirect();

        $record = PlayerRecord::firstOrFail();
        // No structured data — still the classifier-minimal payload
        $this->assertTrue($record->extracted['needs_structured_extraction']);
        $this->assertSame(0, RecordMetric::where('record_id', $record->id)->count());
        $this->assertSame(Submission::STATUS_NEEDS_REVIEW, Submission::firstOrFail()->status);
    }

    public function test_admin_hint_overrides_classifier_when_classifier_confidence_is_below_0_9(): void
    {
        DocumentClassifier::fake([
            [
                // Classifier thinks InBody, but with only 0.8 confidence
                'category' => RecordCategory::INBODY,
                'confidence' => 0.8,
                'reasoning' => 'Sees body-comp terms.',
            ],
        ]);
        // Admin hint wins → category is blood_test → structured extraction
        // fires. Stub the extractor so this test doesn't need a real call.
        BloodTestExtractor::fake([
            ['lab_name' => 'HICARE', 'labs' => []],
        ]);

        $admin = User::factory()->create();
        $player = Player::factory()->create();

        $pdf = UploadedFile::fake()->createWithContent(
            'hinted.pdf',
            MakePdf::withText('Weight 72.5 kg, body fat 11.2 percent'),
        );

        $this->actingAs($admin)
            ->post(
                "/players/{$player->id}/submissions",
                [
                    'files' => [$pdf],
                    // Admin insists this is a blood test
                    'category_hint' => RecordCategory::BLOOD_TEST,
                ],
            )
            ->assertRedirect();

        $record = PlayerRecord::firstOrFail();
        $this->assertSame(
            RecordCategory::where('slug', RecordCategory::BLOOD_TEST)->value('id'),
            $record->category_id,
            'Admin hint wins when classifier confidence < 0.9',
        );
    }

    public function test_non_pdf_mime_marks_attachment_is_text_pdf_false_and_routes_to_review(): void
    {
        DocumentClassifier::fake()->preventStrayPrompts();

        $admin = User::factory()->create();
        $player = Player::factory()->create();

        $image = UploadedFile::fake()->image('scan.jpg');

        $this->actingAs($admin)
            ->post("/players/{$player->id}/submissions", ['files' => [$image]])
            ->assertRedirect();

        $attachment = Attachment::firstOrFail();
        $this->assertFalse($attachment->is_text_pdf);
        $this->assertNull($attachment->extracted_text);

        // No classifier call, submission routed to review
        $this->assertSame(
            Submission::STATUS_NEEDS_REVIEW,
            Submission::firstOrFail()->status,
        );
        $this->assertSame(0, PlayerRecord::count());
    }

    public function test_controller_dispatches_the_chain_for_every_attachment(): void
    {
        Bus::fake();
        DocumentClassifier::fake();

        $admin = User::factory()->create();
        $player = Player::factory()->create();

        $pdfs = [
            UploadedFile::fake()->createWithContent('a.pdf', MakePdf::withText('A')),
            UploadedFile::fake()->createWithContent('b.pdf', MakePdf::withText('B')),
        ];

        $this->actingAs($admin)
            ->post("/players/{$player->id}/submissions", ['files' => $pdfs])
            ->assertRedirect();

        // One chain per attachment, each starting with extraction
        Bus::assertChained([
            ExtractTextFromAttachmentJob::class,
            ClassifyAttachmentJob::class,
        ]);
    }
}
