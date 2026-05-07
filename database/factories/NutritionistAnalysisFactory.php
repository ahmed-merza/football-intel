<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NutritionistAnalysis;
use App\Models\Player;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Realistic-shaped Nutritionist Assistant runs for tests + the dev
 * seeder. Default = completed run with a small but plausible payload;
 * states cover the pending / failed branches of the lifecycle.
 *
 * @extends Factory<NutritionistAnalysis>
 */
class NutritionistAnalysisFactory extends Factory
{
    protected $model = NutritionistAnalysis::class;

    public function definition(): array
    {
        return [
            'player_id' => Player::factory(),
            'generated_by' => null,
            'status' => NutritionistAnalysis::STATUS_COMPLETED,
            'model_used' => 'claude-haiku-4-5',
            'error' => null,
            'summary_text' => 'Iron + Vit D borderline; otherwise within targets.',
            'payload' => [
                'summary' => 'Iron + Vit D borderline; otherwise within targets.',
                'blood_analysis' => [
                    'key_findings' => ['Ferritin 33 ng/mL — low-normal for endurance athlete'],
                    'football_implications' => ['Stamina risk in the 2nd half if iron drops further'],
                ],
                'body_analysis' => [
                    'key_findings' => ['BF 11.4%, skeletal muscle 33.8 kg'],
                    'football_implications' => ['Lean mass good for a midfielder'],
                ],
                'combined_insight' => 'Borderline ferritin + adequate body composition — pre-emptive iron supplementation justified.',
                'recommendations' => [
                    ['area' => 'nutrition', 'action' => 'Add 200g red meat 2×/week'],
                    ['area' => 'supplement', 'action' => 'Iron bisglycinate 25mg with Vitamin C, away from calcium / tea'],
                    ['area' => 'monitoring', 'action' => 'Retest ferritin in 6 weeks'],
                ],
                'risk_flags' => [
                    ['severity' => 'warn', 'kind' => 'iron_deficiency_risk', 'message' => 'Ferritin trending toward iron-deficiency range; act preemptively.'],
                ],
            ],
            'generated_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => NutritionistAnalysis::STATUS_PENDING,
            'summary_text' => null,
            'payload' => null,
            'generated_at' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => NutritionistAnalysis::STATUS_FAILED,
            'summary_text' => null,
            'payload' => null,
            'error' => 'Operation timed out after 300002 milliseconds',
            'generated_at' => null,
        ]);
    }
}
