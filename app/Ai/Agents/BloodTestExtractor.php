<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Second-stage agent: parses a blood-test document's extracted text into
 * typed structured data. Emits the canonical `key` (e.g. hb_g_dl) so the
 * downstream metric fanner writes record_metrics rows without any
 * per-report string matching. The canonical key registry lives in
 * docs/database.md §"Metric key registry".
 *
 * Provider + model resolve from config('ai.football_intel.*'). Prompt is
 * deliberately terse — every extra token is response time, and the
 * canonical keys are semantically obvious enough that the model can map
 * common synonyms ("Hemoglobin" → hb_g_dl) from the key name itself
 * without us listing every variant. Markdown backticks are intentionally
 * avoided in the prompt — the n8n proxy passes prompts through bash,
 * where backticks trigger command substitution.
 */
class BloodTestExtractor implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * Canonical metric keys — flat list, kept in sync with
     * docs/database.md §"Metric key registry". Used for both the schema
     * enum and the instructions. The model infers synonyms from the key
     * name; we don't need to spoon-feed a synonym table.
     *
     * @var list<string>
     */
    private const KEYS = [
        'hb_g_dl', 'hct_pct', 'rbc_10_12_l', 'wbc_10_9_l', 'platelets_10_9_l',
        'ferritin_ng_ml', 'iron_ug_dl', 'tibc_ug_dl', 'transferrin_sat_pct',
        'vit_d_ng_ml', 'vit_b12_pg_ml', 'folate_ng_ml',
        'sodium_mmol_l', 'potassium_mmol_l', 'chloride_mmol_l', 'calcium_mg_dl',
        'glucose_mmol_l', 'hba1c_pct',
        'ast_u_l', 'alt_u_l', 'ggt_u_l', 'alp_u_l', 'bilirubin_total_mg_dl',
        'cholesterol_total_mmol_l', 'ldl_mmol_l', 'hdl_mmol_l', 'triglycerides_mmol_l',
        'cpk_u_l', 'ldh_u_l',
        'tsh_uiu_ml', 't3_ng_dl', 't4_ug_dl',
    ];

    public function provider(): string
    {
        return config('ai.football_intel.providers.extractor');
    }

    public function model(): string
    {
        return config('ai.football_intel.models.extractor');
    }

    /**
     * Structured output over a whole lab panel with a cold-start Ollama
     * model can comfortably run past the 60s default. 300s matches the
     * supervisor-ai Horizon timeout so the HTTP call + job share a budget.
     */
    public function timeout(): int
    {
        return 300;
    }

    public function instructions(): Stringable|string
    {
        $keys = implode(', ', self::KEYS);

        return <<<PROMPT
        You parse a blood-test / lab-panel report into typed JSON.

        For every analyte found in the document emit one entry with:
          name: verbatim from the report
          key: one of the canonical IDs below, or "other" if no fit (never omit)
          value: numeric value as a float
          unit: verbatim from the report
          ref_low / ref_high: numeric bounds, or null if not printed
          flag: "low" if value < ref_low, "high" if value > ref_high, else "normal"

        Critical thresholds override low/high (return "critical"):
          Vitamin D < 20, Ferritin < 15, Sodium < 130 or > 150,
          Potassium < 3.0 or > 5.5, Hb < 10.

        Also populate patient_name_on_report, sample_date (YYYY-MM-DD),
        lab_name (header — e.g. HICARE, Al Kindi).

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
            'sample_date' => $schema->string(),
            'lab_name' => $schema->string(),
            'labs' => $schema
                ->array()
                ->items(
                    $schema->object(fn (JsonSchema $s): array => [
                        'name' => $s->string()->required(),
                        'key' => $s->string()->enum([...self::KEYS, 'other']),
                        'value' => $s->number()->required(),
                        'unit' => $s->string()->required(),
                        'ref_low' => $s->number(),
                        'ref_high' => $s->number(),
                        'flag' => $s->string()->enum(['low', 'normal', 'high', 'critical'])->required(),
                    ]),
                )
                ->required(),
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
