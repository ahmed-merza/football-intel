<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\Submission;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    protected $model = Attachment::class;

    private const SAMPLE_FILENAMES = [
        'blood_test_june_2024.pdf',
        'inbody_report.pdf',
        'gps_session_garmin.pdf',
        'nutrition_plan_v2.pdf',
        'supplement_protocol.pdf',
        'coach_feedback.pdf',
        'match_report.pdf',
        'lab_results.pdf',
    ];

    public function definition(): array
    {
        $pageCount = $this->faker->numberBetween(1, 8);

        return [
            'submission_id' => Submission::factory(),
            'original_filename' => $this->faker->randomElement(self::SAMPLE_FILENAMES),
            'mime_type' => 'application/pdf',
            'size_bytes' => $this->faker->numberBetween(30_000, 500_000),
            'storage_disk' => 'local',
            'storage_path' => 'attachments/'.Str::uuid()->toString().'.pdf',
            'sha256' => hash('sha256', Str::uuid()->toString()),
            'page_count' => $pageCount,
            'is_text_pdf' => true,
            'extracted_text' => 'Lorem ipsum dolor sit amet, blood test placeholder text for '.$pageCount.' pages.',
            'ocr_used' => false,
            'ocr_confidence' => null,
            'thumbnail_path' => null,
        ];
    }

    public function image(): static
    {
        return $this->state(fn (): array => [
            'mime_type' => $this->faker->randomElement(['image/jpeg', 'image/png']),
            'storage_path' => 'attachments/'.Str::uuid()->toString().'.jpg',
            'page_count' => 1,
            'is_text_pdf' => null,
            'ocr_used' => true,
            'ocr_confidence' => $this->faker->randomFloat(2, 0.75, 0.98),
        ]);
    }

    public function ocrNeeded(): static
    {
        return $this->state(fn (): array => [
            'is_text_pdf' => false,
            'ocr_used' => true,
            'ocr_confidence' => $this->faker->randomFloat(2, 0.70, 0.95),
        ]);
    }
}
