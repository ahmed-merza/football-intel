<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\TextPreprocessor;
use Tests\TestCase;

class TextPreprocessorTest extends TestCase
{
    private TextPreprocessor $preprocessor;

    private const SAMPLE_MIXED = "HICARE Medical Centre مركز هاي كير الطبي\nPatient: ABDULRAHMAN MUBARAK عبدالرحمن مبارك\nHaemoglobin 14.2 g/dL ١٤.٢\nFerritin 33 ng/mL مرتفع";

    protected function setUp(): void
    {
        parent::setUp();
        $this->preprocessor = new TextPreprocessor;
    }

    public function test_classifier_strips_arabic_when_enabled(): void
    {
        config([
            'ai.football_intel.preprocess.strip_arabic.classifier' => true,
            'ai.football_intel.classifier_max_chars' => 0,
        ]);

        $result = $this->preprocessor->forClassifier(self::SAMPLE_MIXED);

        $this->assertStringNotContainsString('مركز', $result);
        $this->assertStringNotContainsString('عبدالرحمن', $result);
        $this->assertStringContainsString('HICARE', $result);
        $this->assertStringContainsString('Haemoglobin 14.2 g/dL', $result);
        $this->assertStringContainsString('Ferritin 33 ng/mL', $result);
    }

    public function test_classifier_keeps_arabic_when_disabled(): void
    {
        config([
            'ai.football_intel.preprocess.strip_arabic.classifier' => false,
            'ai.football_intel.classifier_max_chars' => 0,
        ]);

        $result = $this->preprocessor->forClassifier(self::SAMPLE_MIXED);

        $this->assertStringContainsString('مركز', $result);
        $this->assertStringContainsString('HICARE', $result);
    }

    public function test_classifier_max_chars_of_zero_does_not_truncate(): void
    {
        config([
            'ai.football_intel.preprocess.strip_arabic.classifier' => false,
            'ai.football_intel.classifier_max_chars' => 0,
        ]);

        $long = str_repeat('x', 10_000);

        $this->assertSame($long, $this->preprocessor->forClassifier($long));
    }

    public function test_classifier_caps_at_configured_length(): void
    {
        config([
            'ai.football_intel.preprocess.strip_arabic.classifier' => false,
            'ai.football_intel.classifier_max_chars' => 100,
        ]);

        $result = $this->preprocessor->forClassifier(str_repeat('x', 500));

        $this->assertSame(100, mb_strlen($result));
    }

    public function test_extractor_defaults_keep_arabic_and_never_truncate(): void
    {
        config([
            'ai.football_intel.preprocess.strip_arabic.extractor' => false,
            'ai.football_intel.preprocess.strip_noise.extractor' => false,
        ]);

        $long = self::SAMPLE_MIXED.str_repeat("\nFiller analyte 9.9 mg/dL", 500);
        $result = $this->preprocessor->forExtractor($long);

        $this->assertStringContainsString('مركز', $result);
        $this->assertStringContainsString('Filler analyte', $result);
        $this->assertSame(mb_strlen($long), mb_strlen($result));
    }

    public function test_extractor_strips_arabic_when_enabled(): void
    {
        config([
            'ai.football_intel.preprocess.strip_arabic.extractor' => true,
        ]);

        $result = $this->preprocessor->forExtractor(self::SAMPLE_MIXED);

        $this->assertStringNotContainsString('مركز', $result);
        $this->assertStringContainsString('Haemoglobin', $result);
    }

    public function test_arabic_indic_digits_are_stripped_with_arabic_text(): void
    {
        config(['ai.football_intel.preprocess.strip_arabic.classifier' => true]);

        // "١٤.٢" = Arabic-Indic "14.2"
        $result = $this->preprocessor->forClassifier('value ١٤.٢ here');

        $this->assertStringNotContainsString('١', $result);
        $this->assertStringContainsString('value', $result);
        $this->assertStringContainsString('here', $result);
    }

    public function test_extractor_strips_known_noise_lines_when_enabled(): void
    {
        config([
            'ai.football_intel.preprocess.strip_arabic.extractor' => false,
            'ai.football_intel.preprocess.strip_noise.extractor' => true,
        ]);

        $text = implode("\n", [
            'Test Name        Result   Ref. Range   Unit',
            'Haemoglobin      14.2     13.0-17.5   g/dL',
            'Ferritin         33       22-322      ng/mL',
            'Reviewed By: Dr. Amany Fayez',
            'NHRA License: 11010347',
            'Verified By : Amany Fayez   PM',
            'Page 1',
            'Page 1 of 4',
            'Confidential -- Right Calories Sports Nutrition | CEO: Abdulla Selaibeekh | April 2026',
        ]);

        $result = $this->preprocessor->forExtractor($text);

        $this->assertStringContainsString('Haemoglobin', $result);
        $this->assertStringContainsString('Ferritin', $result);
        $this->assertStringNotContainsString('Reviewed By', $result);
        $this->assertStringNotContainsString('NHRA License', $result);
        $this->assertStringNotContainsString('Verified By', $result);
        $this->assertStringNotContainsString('Page 1', $result);
        $this->assertStringNotContainsString('Confidential', $result);
    }

    public function test_extractor_keeps_noise_when_disabled(): void
    {
        config([
            'ai.football_intel.preprocess.strip_arabic.extractor' => false,
            'ai.football_intel.preprocess.strip_noise.extractor' => false,
        ]);

        $text = "Haemoglobin 14.2 g/dL\nReviewed By: Dr. X\nPage 1";
        $result = $this->preprocessor->forExtractor($text);

        $this->assertStringContainsString('Reviewed By', $result);
        $this->assertStringContainsString('Page 1', $result);
    }

    public function test_extractor_collapses_repeated_page_headers(): void
    {
        config([
            'ai.football_intel.preprocess.strip_arabic.extractor' => false,
            'ai.football_intel.preprocess.strip_noise.extractor' => true,
        ]);

        // 5-page report where the column header is reprinted on every
        // page; with threshold=3 it should collapse to a single copy.
        // Body lines are unique per page (different analytes / values)
        // so they must NOT be collapsed.
        $header = 'Test Name        Result   Ref. Range   Unit';
        $bodyLines = [
            'Haemoglobin      14.2     13.0-17.5   g/dL',
            'Ferritin         33       22-322      ng/mL',
            'Vitamin D        28       30-100      ng/mL',
            'Sodium          138      136-145      mmol/L',
            'Potassium       4.2      3.5-5.1      mmol/L',
        ];
        $lines = [];
        foreach ($bodyLines as $body) {
            $lines[] = $header;
            $lines[] = $body;
        }

        $result = $this->preprocessor->forExtractor(implode("\n", $lines));

        // The header survives once; the per-page reprints are gone.
        $this->assertSame(1, substr_count($result, $header));
        // Each unique body line survives.
        foreach ($bodyLines as $body) {
            $this->assertStringContainsString($body, $result);
        }
    }

    public function test_extractor_does_not_strip_lab_values_that_resemble_page_lines(): void
    {
        config([
            'ai.football_intel.preprocess.strip_arabic.extractor' => false,
            'ai.football_intel.preprocess.strip_noise.extractor' => true,
        ]);

        // Edge case: an analyte line ending in "Page 4" would be a
        // disaster. Confirm a typical analyte row survives untouched
        // (the Page-N pattern only matches when the line ENDS with
        // "Page N", so this row is safe because the unit follows).
        $line = 'Vitamin D (25-OH)     28.0     30-100      ng/mL';
        $result = $this->preprocessor->forExtractor($line);

        $this->assertStringContainsString('Vitamin D', $result);
        $this->assertStringContainsString('28.0', $result);
    }
}
