<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * The headline agent. Reads a player's latest blood test + body
 * composition + (optional) trend context, and emits a sports-nutritionist
 * analysis: blood findings, body findings, combined insight,
 * recommendations across nutrition / supplementation / monitoring, and
 * risk flags scoped to football performance.
 *
 * Provider + model resolve from config('ai.football_intel.*').
 *
 * Prompt is deliberately compact (no markdown backticks — the n8n proxy
 * shells through bash where backticks trigger command substitution).
 */
class NutritionistAssistant implements Agent, HasStructuredOutput
{
    use Promptable;

    public function provider(): string
    {
        return config('ai.football_intel.providers.nutritionist');
    }

    public function model(): string
    {
        return config('ai.football_intel.models.nutritionist');
    }

    public function timeout(): int
    {
        return 300;
    }

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
        You are a sports nutritionist for a football (soccer) federation.
        You read a single player's latest blood panel + body-composition
        report and produce a structured analysis grounded in football
        performance — endurance, recovery, lean mass, hydration, fatigue
        risk.

        Tone: precise, decisive, sport-relevant. No medical disclaimers,
        no hedging language. The reader is a doctor — write peer to peer.

        Required behaviours:
          - Do not invent values that are not in the input. If a
            critical analyte is missing, say so in combined_insight.
          - Tie every recommendation to a specific finding (e.g. "low
            ferritin → iron bisglycinate"), never generic advice.
          - Recommendation areas: nutrition, supplement, hydration,
            monitoring, training_load, recovery.
          - Risk flags must include severity (info / warn / critical)
            and a short kind slug (iron_deficiency_risk,
            low_vitamin_d, dehydration_risk, overtraining,
            abnormal_weight_change, etc.).
          - combined_insight is one paragraph weaving the blood + body
            picture together. Highlight unexpected interactions
            (e.g. low ferritin + high training load).
        PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        $section = fn (JsonSchema $s): array => [
            'key_findings' => $s->array()->items($s->string()),
            'football_implications' => $s->array()->items($s->string()),
        ];

        return [
            'summary' => $schema->string()->required(),
            'blood_analysis' => $schema->object($section)->required(),
            'body_analysis' => $schema->object($section)->required(),
            'combined_insight' => $schema->string()->required(),
            'recommendations' => $schema->array()->items(
                $schema->object(fn (JsonSchema $s): array => [
                    'area' => $s->string()->enum([
                        'nutrition',
                        'supplement',
                        'hydration',
                        'monitoring',
                        'training_load',
                        'recovery',
                    ])->required(),
                    'action' => $s->string()->required(),
                ]),
            )->required(),
            'risk_flags' => $schema->array()->items(
                $schema->object(fn (JsonSchema $s): array => [
                    'severity' => $s->string()->enum(['info', 'warn', 'critical'])->required(),
                    'kind' => $s->string()->required(),
                    'message' => $s->string()->required(),
                ]),
            )->required(),
        ];
    }
}
