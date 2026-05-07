<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Models\RecordCategory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * First-stage router in the AI pipeline: given an attachment's extracted
 * text, pick one of the 8 canonical record categories (blood_test, inbody,
 * gps_wearable, nutrition_plan, hydration_supplement_plan, coach_feedback,
 * match_activity, other). The downstream category-specific extractors only
 * run after this agent picks a non-`other` slug.
 *
 * Provider + model resolve from config('ai.football_intel.*') so they can
 * swap per env without touching this class — Ollama in dev (free), Claude
 * Haiku 4.5 in prod (cheap + accurate), or a Gemini Flash / Groq Llama
 * combo if the economics shift.
 */
class DocumentClassifier implements Agent, HasStructuredOutput
{
    use Promptable;

    public function provider(): string
    {
        return config('ai.football_intel.providers.classifier');
    }

    public function model(): string
    {
        return config('ai.football_intel.models.classifier');
    }

    /**
     * Classification is a short call, but Ollama cold-loads a 14B model
     * on first use. 300s matches the supervisor-ai Horizon timeout so
     * the HTTP call + job share one budget.
     */
    public function timeout(): int
    {
        return 300;
    }

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
        You classify football-player documents into ONE of eight categories.

        Categories and what they look like:
          - blood_test — lab panels: CBC, biochemistry, lipids, vitamins, iron,
            thyroid. Contains analytes with values, units, and reference ranges
            (e.g. "Haemoglobin 14.2 g/dL", "Ferritin 33 ng/mL").
          - inbody — body composition test. Weight, body fat %, skeletal muscle
            mass, visceral fat, phase angle. Often branded "InBody" / "DEXA".
          - gps_wearable — session export from Garmin / Polar / WHOOP /
            Catapult / StatSports. Distance, high-speed runs, sprints, HR,
            training load.
          - nutrition_plan — daily meal plan with macro targets (kcal, protein,
            carbs, fat, water).
          - hydration_supplement_plan — hydration protocol + supplement stack
            with dosing and timing.
          - coach_feedback — free-text feedback from coaches or medical staff
            about a session / player state.
          - match_activity — match-day record: opponent, minutes played,
            distance, sprints, goals, assists.
          - other — genuinely fits no other category. Use sparingly.

        Respond with the structured output schema. Be decisive on confidence:
          - 0.9+ when multiple defining terms appear
          - 0.7–0.9 when the category is clear but document is partial
          - below 0.7 only if you're really unsure (will be sent for admin review)
        PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'category' => $schema->string()->enum([
                RecordCategory::BLOOD_TEST,
                RecordCategory::INBODY,
                RecordCategory::GPS_WEARABLE,
                RecordCategory::NUTRITION_PLAN,
                RecordCategory::HYDRATION_SUPPLEMENT_PLAN,
                RecordCategory::COACH_FEEDBACK,
                RecordCategory::MATCH_ACTIVITY,
                RecordCategory::OTHER,
            ])->required(),
            'confidence' => $schema->number()->required(),
            'reasoning' => $schema->string()->required(),
        ];
    }
}
