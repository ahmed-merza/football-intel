<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Second-stage agent: parses a nutrition-plan PDF (Right Calories,
 * Kameel-style plans, etc.) into typed structured data.
 *
 * Output mirrors the BloodTestExtractor / InBodyExtractor row format:
 * daily macro/water targets land in `metrics[]` with canonical keys so
 * the same RecordMetricFanner walks them. Meals + supplements are
 * structured-but-non-metric — they ride along on `extracted` as nested
 * arrays for the Nutritionist Assistant prompt + the Documents tab.
 *
 * Plans almost always print one number per target with no range, so
 * ref_low/ref_high default to null and flag stays "normal" — they're
 * populated only if the plan itself prints periodised bounds.
 */
class NutritionPlanExtractor implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * Canonical metric keys — kept in sync with docs/database.md
     * §"Nutrition plan". The model maps from the printed line label
     * ("Daily energy", "Total protein", "Hydration goal") via the
     * canonical key name itself.
     *
     * @var list<string>
     */
    private const KEYS = [
        'daily_kcal',
        'daily_protein_g',
        'daily_carb_g',
        'daily_fat_g',
        'daily_water_l',
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
        You parse a sports nutrition plan into typed JSON. The document
        is usually a multi-page protocol with daily macro targets, a
        meal schedule, and supplements.

        For every daily target listed at the top of the plan (kcal,
        protein, carbs, fats, water) emit one entry under metrics with:
          name: verbatim from the plan ("Daily energy", "Total protein", ...)
          key: one of the canonical IDs below, or "other" if no fit
          value: numeric value as a float
          unit: kcal, g, L (verbatim where shown)
          ref_low / ref_high: bounds if the plan is periodised, else null
          flag: "low"/"high" only when value falls outside printed bounds; else "normal"

        Also populate:
          plan_name: title at the top of the plan if printed
          patient_name_on_report: subject the plan is written for
          plan_start_date / plan_end_date (YYYY-MM-DD), or null

        Then capture the meal schedule:
          meals: array of { name, time (HH:MM or null), items: [...] }
            items: array of { food, grams, protein_g, carb_g, fat_g, kcal }
            Numeric fields are null when the plan does not print them.

        And the supplement protocol if present:
          supplements: array of { name, dose, timing, purpose }

        notes: any free-form coach/nutritionist remarks at the end.

        Never invent values. Use null for absent numbers; do not guess.

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
            'plan_name' => $schema->string(),
            'patient_name_on_report' => $schema->string(),
            'plan_start_date' => $schema->string(),
            'plan_end_date' => $schema->string(),
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
            'meals' => $schema->array()->items(
                $schema->object(fn (JsonSchema $s): array => [
                    'name' => $s->string()->required(),
                    'time' => $s->string(),
                    'items' => $s->array()->items(
                        $s->object(fn (JsonSchema $i): array => [
                            'food' => $i->string()->required(),
                            'grams' => $i->number(),
                            'protein_g' => $i->number(),
                            'carb_g' => $i->number(),
                            'fat_g' => $i->number(),
                            'kcal' => $i->number(),
                        ]),
                    ),
                ]),
            ),
            'supplements' => $schema->array()->items(
                $schema->object(fn (JsonSchema $s): array => [
                    'name' => $s->string()->required(),
                    'dose' => $s->string(),
                    'timing' => $s->string(),
                    'purpose' => $s->string(),
                ]),
            ),
            'notes' => $schema->string(),
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
