<?php

declare(strict_types=1);

namespace App\Services\Medical;

use App\Models\PlayerRecord;
use App\Models\RecordCategory;
use App\Models\RecordMetric;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Takes a PlayerRecord whose `extracted` payload was produced by a
 * structured-output extractor (BloodTestExtractor, InBodyExtractor, …)
 * and fans out record_metrics rows — one per entry that mapped to a
 * canonical metric key. Idempotent: safe to re-run after an admin
 * correction; old metric rows for the same record are deleted first.
 *
 * Source array name is per-category — blood uses `labs`, InBody uses
 * `metrics` — but everything past that is identical, so a single
 * fanner serves both.
 *
 * Kept as a plain service (not an observer) so the fan-out is explicit
 * in the job chain and testable without event plumbing.
 */
class RecordMetricFanner
{
    /**
     * Source array key on `player_records.extracted` for each category
     * we know how to fan. Blood-test PDFs hold their analytes under
     * `labs`; InBody printouts and nutrition plans hold theirs under
     * `metrics`. Adding a new category = one entry here, no fanner-side
     * logic.
     */
    private const SOURCE_KEY_BY_CATEGORY = [
        RecordCategory::BLOOD_TEST => 'labs',
        RecordCategory::INBODY => 'metrics',
        RecordCategory::NUTRITION_PLAN => 'metrics',
    ];

    /**
     * @return int Number of metric rows written.
     */
    public function fan(PlayerRecord $record): int
    {
        $sourceKey = self::SOURCE_KEY_BY_CATEGORY[$record->category->slug] ?? null;
        if ($sourceKey === null) {
            throw new InvalidArgumentException(
                "RecordMetricFanner has no source-key mapping for category '{$record->category->slug}'.",
            );
        }

        $items = $record->extracted[$sourceKey] ?? [];
        if (! is_array($items) || $items === []) {
            // Still wipe any stale rows for this record so re-runs after
            // an extraction that emptied the array don't leave dangling metrics.
            DB::transaction(fn () => RecordMetric::where('record_id', $record->id)->forceDelete());

            return 0;
        }

        return DB::transaction(function () use ($record, $items): int {
            // Drop existing fan-out for this record so re-runs produce a
            // clean set (no stale keys, no doubled rows).
            RecordMetric::where('record_id', $record->id)->forceDelete();

            $written = 0;
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $key = $item['key'] ?? null;
                if ($key === null || $key === 'other' || $key === '') {
                    // Skip entries the extractor couldn't map to a canonical
                    // key — they're retained in `extracted` for reference
                    // but don't show up in trend charts.
                    continue;
                }
                if (! array_key_exists('value', $item) || ! is_numeric($item['value'])) {
                    continue;
                }

                RecordMetric::create([
                    'record_id' => $record->id,
                    'player_id' => $record->player_id,
                    'category_id' => $record->category_id,
                    'record_date' => $record->record_date,
                    'metric_key' => $key,
                    'metric_value' => (float) $item['value'],
                    'unit' => is_string($item['unit'] ?? null) ? $item['unit'] : null,
                    'ref_low' => $this->asFloat($item['ref_low'] ?? null),
                    'ref_high' => $this->asFloat($item['ref_high'] ?? null),
                    'flag' => is_string($item['flag'] ?? null) ? $item['flag'] : RecordMetric::FLAG_NORMAL,
                ]);
                $written++;
            }

            return $written;
        });
    }

    private function asFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
