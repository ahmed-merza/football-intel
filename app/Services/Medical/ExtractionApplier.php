<?php

declare(strict_types=1);

namespace App\Services\Medical;

use App\Models\PlayerRecord;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Single source of truth for "an extractor produced a typed payload —
 * write it onto the player_records row, fan canonical metrics, set
 * the summary line, and clear stale extraction-state flags".
 *
 * Both the sync ExtractStructuredDataJob and the async N8nWebhookController
 * end up here; they only differ in *where* the payload came from.
 *
 * Per-kind methods are public so the call site is self-documenting
 * and we don't have to ship a category slug as a string parameter.
 */
class ExtractionApplier
{
    public function __construct(private RecordMetricFanner $fanner) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function applyBloodTest(PlayerRecord $record, array $payload): void
    {
        $classifier = $record->extracted['classifier'] ?? null;

        $extracted = array_merge(
            $payload,
            // Preserve the classifier breadcrumb for audit / review-queue.
            ['classifier' => $classifier],
        );

        $this->clearExtractionStateFlags($extracted);

        $record->update([
            'extracted' => $extracted,
            'source_lab' => is_string($payload['lab_name'] ?? null) ? $payload['lab_name'] : $record->source_lab,
            'record_date' => $this->parseDate($payload['sample_date'] ?? null) ?? $record->record_date,
            'summary_text' => $this->summariseFlaggedRows(
                $payload['labs'] ?? null,
                'lab analytes',
                $record->summary_text,
            ),
        ]);

        $this->fanner->fan($record->fresh());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function applyInBody(PlayerRecord $record, array $payload): void
    {
        $classifier = $record->extracted['classifier'] ?? null;

        $extracted = array_merge(
            $payload,
            ['classifier' => $classifier],
        );

        $this->clearExtractionStateFlags($extracted);

        $record->update([
            'extracted' => $extracted,
            // InBody printouts identify the device, not the lab — but the
            // source_lab column is the closest free-text slot we have.
            'source_lab' => is_string($payload['device'] ?? null) ? $payload['device'] : $record->source_lab,
            'record_date' => $this->parseDate($payload['test_date'] ?? null) ?? $record->record_date,
            'summary_text' => $this->summariseFlaggedRows(
                $payload['metrics'] ?? null,
                'body-comp metrics',
                $record->summary_text,
            ),
        ]);

        $this->fanner->fan($record->fresh());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function applyNutritionPlan(PlayerRecord $record, array $payload): void
    {
        $classifier = $record->extracted['classifier'] ?? null;

        $extracted = array_merge(
            $payload,
            ['classifier' => $classifier],
        );

        $this->clearExtractionStateFlags($extracted);

        // Plans rarely carry a "lab" but almost always have a plan name
        // (the document header). Fall back to existing source_lab so a
        // re-run doesn't blank a value that came from elsewhere.
        $sourceLab = is_string($payload['plan_name'] ?? null) ? $payload['plan_name'] : $record->source_lab;

        $record->update([
            'extracted' => $extracted,
            'source_lab' => $sourceLab,
            'record_date' => $this->parseDate($payload['plan_start_date'] ?? null) ?? $record->record_date,
            'summary_text' => $this->summariseNutritionPlan($payload, $record->summary_text),
        ]);

        $this->fanner->fan($record->fresh());
    }

    /**
     * Strip any stale extraction-state flags from the extracted payload —
     * a successful apply means the previous failure / pending / partial
     * states no longer hold.
     *
     * @param  array<string, mixed>  $extracted
     */
    private function clearExtractionStateFlags(array &$extracted): void
    {
        unset(
            $extracted['needs_structured_extraction'],
            $extracted['structured_extraction_pending'],
            $extracted['extraction_failed'],
            $extracted['extraction_failed_at'],
            $extracted['extraction_failure_reason'],
            $extracted['extraction_partial'],
            $extracted['chunks_completed'],
            $extracted['chunks_total'],
            $extracted['failed_chunk_index'],
            $extracted['split_chunks'],
            $extracted['awaiting_callback'],
            $extracted['callback_correlation_id'],
        );
    }

    private function parseDate(mixed $raw): ?string
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        try {
            return Carbon::parse($raw)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Shared one-line summary for blood + InBody payloads — both share
     * the `{name, flag, ...}` row shape so the counting logic is the
     * same. Three flag levels are surfaced (low/high/critical); "normal"
     * is the dominant case.
     */
    private function summariseFlaggedRows(mixed $rows, string $label, ?string $existing): ?string
    {
        if (! is_array($rows) || $rows === []) {
            return $existing;
        }

        $flagged = array_filter(
            $rows,
            fn (mixed $row): bool => is_array($row)
                && isset($row['flag'])
                && in_array($row['flag'], ['low', 'high', 'critical'], true),
        );

        if ($flagged === []) {
            return sprintf('%d %s, all within reference range.', count($rows), $label);
        }

        $topIssues = array_slice(
            array_map(
                fn (array $row): string => ($row['name'] ?? 'value').' '.$row['flag'],
                array_values($flagged),
            ),
            0,
            3,
        );

        return sprintf(
            '%d %s — %d flagged (%s).',
            count($rows),
            $label,
            count($flagged),
            implode(', ', $topIssues),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function summariseNutritionPlan(array $payload, ?string $existing): ?string
    {
        $metrics = $payload['metrics'] ?? null;
        if (! is_array($metrics) || $metrics === []) {
            return $existing;
        }

        $kcal = null;
        $protein = null;
        foreach ($metrics as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (($row['key'] ?? null) === 'daily_kcal' && is_numeric($row['value'] ?? null)) {
                $kcal = (int) round((float) $row['value']);
            }
            if (($row['key'] ?? null) === 'daily_protein_g' && is_numeric($row['value'] ?? null)) {
                $protein = (int) round((float) $row['value']);
            }
        }

        $meals = is_array($payload['meals'] ?? null) ? count($payload['meals']) : 0;
        $supplements = is_array($payload['supplements'] ?? null) ? count($payload['supplements']) : 0;

        $parts = [];
        if ($kcal !== null) {
            $parts[] = "{$kcal} kcal";
        }
        if ($protein !== null) {
            $parts[] = "{$protein}g protein";
        }
        if ($meals > 0) {
            $parts[] = "{$meals} meals";
        }
        if ($supplements > 0) {
            $parts[] = "{$supplements} supplements";
        }

        return $parts === []
            ? $existing
            : 'Plan — '.implode(', ', $parts).'.';
    }
}
