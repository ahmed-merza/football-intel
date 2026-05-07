<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Second-stage agent: parses an InBody / body-composition printout
 * into typed structured data. Output mirrors BloodTestExtractor's
 * `{name, key, value, unit, ref_low, ref_high, flag}` row format on
 * purpose so the same RecordMetricFanner fans both categories.
 *
 * Reference ranges come straight off the InBody printout — every
 * model prints them — so flag is computed from the printed bounds
 * rather than from sex/age/ethnicity tables we don't carry.
 *
 * Provider + model resolve from config('ai.football_intel.*') like
 * the blood extractor; the same supervisor-ai timeout (300s) covers
 * a cold-start Ollama run.
 */
class InBodyExtractor implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * Canonical metric keys — kept in sync with docs/database.md
     * §"InBody / body composition". The model maps synonyms from
     * the key name itself ("Skeletal Muscle Mass" → skeletal_muscle_kg).
     *
     * @var list<string>
     */
    private const KEYS = [
        'weight_kg',
        'body_fat_pct',
        'body_fat_kg',
        'skeletal_muscle_kg',
        'visceral_fat',
        'phase_angle',
        'tbw_l',
        'icw_l',
        'ecw_l',
    ];

    public function provider(): string
    {
        return config('ai.football_intel.providers.extractor');
    }

    public function model(): string
    {
        return config('ai.football_intel.models.extractor');
    }

    public function timeout(): int
    {
        return 300;
    }

    public function instructions(): Stringable|string
    {
        $keys = implode(', ', self::KEYS);

        return <<<PROMPT
        You parse an InBody / body-composition report into typed JSON.

        For every measurement found in the report emit one entry with:
          name: verbatim from the report (e.g. "Skeletal Muscle Mass")
          key: one of the canonical IDs below, or "other" if no fit (never omit)
          value: numeric value as a float
          unit: verbatim from the report (kg, %, L, deg, etc.)
          ref_low / ref_high: numeric bounds shown on the printout, or null if absent
          flag: "low" if value < ref_low, "high" if value > ref_high, else "normal"

        Also populate:
          patient_name_on_report: subject name if printed
          test_date (YYYY-MM-DD)
          device: model line if printed (e.g. "InBody 770", "InBody 270")
          segmental: per-limb skeletal-muscle weights in kg if printed
            keys: right_arm_kg, left_arm_kg, trunk_kg, right_leg_kg, left_leg_kg

        If a value has qualifiers (< 3.0, > 220), use the numeric portion.
        Never invent reference ranges. Return them as null when absent.

        Canonical key IDs:
        {$keys}
        PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'patient_name_on_report' => $schema->string(),
            'test_date' => $schema->string(),
            'device' => $schema->string(),
            'metrics' => $schema
                ->array()
                ->items(
                    $schema->object(fn (JsonSchema $s): array => [
                        'name' => $s->string()->required(),
                        'key' => $s->string()->enum([...self::KEYS, 'other']),
                        'value' => $s->number()->required(),
                        'unit' => $s->string()->required(),
                        'ref_low' => $s->number(),
                        'ref_high' => $s->number(),
                        'flag' => $s->string()->enum(['low', 'normal', 'high'])->required(),
                    ]),
                )
                ->required(),
            'segmental' => $schema->object(fn (JsonSchema $s): array => [
                'right_arm_kg' => $s->number(),
                'left_arm_kg' => $s->number(),
                'trunk_kg' => $s->number(),
                'right_leg_kg' => $s->number(),
                'left_leg_kg' => $s->number(),
            ]),
        ];
    }

    /**
     * @return list<string>
     */
    public static function registeredKeys(): array
    {
        return self::KEYS;
    }
}
