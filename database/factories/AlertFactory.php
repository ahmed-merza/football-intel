<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Alert;
use App\Models\Player;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Alert>
 */
class AlertFactory extends Factory
{
    protected $model = Alert::class;

    public function definition(): array
    {
        $kind = $this->faker->randomElement([
            'low_vit_d', 'iron_deficiency_risk', 'overtraining',
            'abnormal_weight_change', 'dehydration_risk', 'bf_delta_high',
        ]);

        return [
            'player_id' => Player::factory(),
            'record_id' => null,
            'severity' => Alert::SEVERITY_WARN,
            'kind' => $kind,
            'message' => $this->messageFor($kind),
            'acknowledged_at' => null,
            'acknowledged_by' => null,
        ];
    }

    public function critical(): static
    {
        return $this->state(fn (): array => ['severity' => Alert::SEVERITY_CRITICAL]);
    }

    public function info(): static
    {
        return $this->state(fn (): array => ['severity' => Alert::SEVERITY_INFO]);
    }

    public function acknowledged(): static
    {
        return $this->state(fn (): array => [
            'acknowledged_at' => $this->faker->dateTimeBetween('-2 months', 'now'),
        ]);
    }

    private function messageFor(string $kind): string
    {
        return match ($kind) {
            'low_vit_d' => 'Vitamin D below 30 ng/mL — supplement with D3 + K2 and retest in 8 weeks.',
            'iron_deficiency_risk' => 'Ferritin below 30 ng/mL — endurance risk for a midfielder/forward.',
            'overtraining' => 'Weekly training load trending up; HRV down. Consider a recovery week.',
            'abnormal_weight_change' => 'Weight change >3% month-over-month — investigate cause.',
            'dehydration_risk' => 'Sodium + urine markers suggest chronic under-hydration.',
            'bf_delta_high' => 'Body fat % up >3pp in 30 days — revisit nutrition plan compliance.',
            default => 'Flagged for review.',
        };
    }
}
