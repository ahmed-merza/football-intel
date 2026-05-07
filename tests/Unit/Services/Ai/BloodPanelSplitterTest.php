<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\BloodPanelSplitter;
use Tests\TestCase;

class BloodPanelSplitterTest extends TestCase
{
    private BloodPanelSplitter $splitter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->splitter = new BloodPanelSplitter;
    }

    public function test_returns_single_chunk_when_no_section_markers_found(): void
    {
        $text = "Patient: ABDULRAHMAN\nHaemoglobin 14.2 g/dL\nFerritin 33 ng/mL";

        $chunks = $this->splitter->split($text);

        $this->assertCount(1, $chunks);
        $this->assertSame($text, $chunks[0]);
    }

    public function test_returns_single_chunk_when_only_one_section_marker_found(): void
    {
        $text = "Patient: ABDULRAHMAN\nCOMPLETE HEMOGRAM\nHaemoglobin 14.2 g/dL\nFerritin 33 ng/mL";

        $chunks = $this->splitter->split($text);

        $this->assertCount(1, $chunks);
        $this->assertSame($text, $chunks[0]);
    }

    public function test_splits_on_two_or_more_recognised_sections(): void
    {
        // Body sections sized to mirror real lab printouts (well past
        // the splitter's MIN_CHUNK_BYTES merge threshold).
        $cbcRows = str_repeat("WBC 5.0 10^9/L 3.5-9.5 normal\nHb 14.2 g/dL 13-17.5 normal\n", 5);
        $lipidRows = str_repeat("Cholesterol 4.4 mmol/L 3.6-5.2 normal\nHDL 2.2 mmol/L 0.8-1.8 high\n", 5);
        $liverRows = str_repeat("AST 32 U/L <34 normal\nALT 30 U/L 10-49 normal\n", 6);

        $text = implode("\n", [
            'HICARE Medical Centre',
            'Patient: ABDULRAHMAN MUBARAK',
            'Sample Date: 17/06/2024',
            'Test Name | Result | Ref. Range | Unit',
            'COMPLETE HEMOGRAM',
            $cbcRows,
            'LIPID PROFILE',
            $lipidRows,
            'LIVER FUNCTION TEST',
            $liverRows,
        ]);

        $chunks = $this->splitter->split($text);

        $this->assertCount(3, $chunks);
        // Each chunk carries the document header (HICARE / patient / column header).
        foreach ($chunks as $chunk) {
            $this->assertStringContainsString('HICARE Medical Centre', $chunk);
            $this->assertStringContainsString('Patient: ABDULRAHMAN MUBARAK', $chunk);
            $this->assertStringContainsString('Test Name | Result | Ref. Range', $chunk);
        }
        // Each chunk owns exactly one section.
        $this->assertStringContainsString('COMPLETE HEMOGRAM', $chunks[0]);
        $this->assertStringNotContainsString('LIPID PROFILE', $chunks[0]);
        $this->assertStringContainsString('LIPID PROFILE', $chunks[1]);
        $this->assertStringNotContainsString('LIVER FUNCTION TEST', $chunks[1]);
        $this->assertStringContainsString('LIVER FUNCTION TEST', $chunks[2]);
    }

    public function test_section_match_is_anchored_to_line_start(): void
    {
        // "iron" inside an analyte name must not trigger a split.
        // Both sections need to be ≥ MIN_CHUNK_BYTES so they're not
        // merged by the parent-child collapser.
        $cbcBody = str_repeat("Haemoglobin 14.2 g/dL 13-17.5 normal\n", 8);
        $ironBody = str_repeat("Ferritin 33 ng/mL 22-322 normal\nserum iron 80 ug/dL\n", 6);
        $text = "Patient: TEST\nCOMPLETE HEMOGRAM\n{$cbcBody}IRON STUDIES\n{$ironBody}";

        $chunks = $this->splitter->split($text);

        // Two real section markers (HEMOGRAM + IRON STUDIES) = 2 chunks.
        $this->assertCount(2, $chunks);
    }

    public function test_section_match_is_case_insensitive(): void
    {
        $cbcBody = str_repeat("Haemoglobin 14.2 g/dL 13-17.5 normal\n", 8);
        $lipidBody = str_repeat("Cholesterol 4.4 mmol/L 3.6-5.2 normal\n", 8);
        $text = "Patient: TEST\nComplete Hemogram\n{$cbcBody}lipid profile\n{$lipidBody}";

        $chunks = $this->splitter->split($text);

        $this->assertCount(2, $chunks);
    }

    public function test_tiny_parent_section_merges_into_next_section(): void
    {
        // Federation panels often print HAEMATOLOGY as a parent header
        // with COMPLETE BLOOD COUNT (CBC) right underneath; the
        // HAEMATOLOGY chunk is essentially empty. Merging keeps the
        // CBC body together.
        $cbcBody = str_repeat("Haemoglobin 12.7 g/dL 12-14.5 normal\n", 10);
        $lipidBody = str_repeat("Cholesterol 4.4 mmol/L 3.6-5.2 normal\n", 10);
        $text = "Patient: TEST\nHAEMATOLOGY\nESR 6\nCOMPLETE BLOOD COUNT (CBC)\n{$cbcBody}LIPID PROFILE\n{$lipidBody}";

        $chunks = $this->splitter->split($text);

        // Three markers but the tiny HAEMATOLOGY chunk merges forward
        // into the CBC section — net 2 useful chunks.
        $this->assertCount(2, $chunks);
        // The merged CBC chunk still carries both markers + body.
        $this->assertStringContainsString('HAEMATOLOGY', $chunks[0]);
        $this->assertStringContainsString('COMPLETE BLOOD COUNT (CBC)', $chunks[0]);
        $this->assertStringContainsString('Haemoglobin 12.7', $chunks[0]);
        $this->assertStringContainsString('LIPID PROFILE', $chunks[1]);
    }

    public function test_empty_input_returns_single_empty_chunk(): void
    {
        $chunks = $this->splitter->split('');

        $this->assertCount(1, $chunks);
        $this->assertSame('', $chunks[0]);
    }
}
