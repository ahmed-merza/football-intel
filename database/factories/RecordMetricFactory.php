<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Player;
use App\Models\PlayerRecord;
use App\Models\RecordCategory;
use App\Models\RecordMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Default produces a plausible Haemoglobin reading. Use the state helpers
 * (`ferritin()`, `vitaminD()`, `bodyFat()`, etc.) or pass `metric_key` +
 * `metric_value` explicitly when building targeted test data.
 *
 * @extends Factory<RecordMetric>
 */
class RecordMetricFactory extends Factory
{
    protected $model = RecordMetric::class;

    public function definition(): array
    {
        return [
            'record_id' => PlayerRecord::factory(),
            'player_id' => Player::factory(),
            'category_id' => fn (): int => RecordCategory::where('slug', RecordCategory::BLOOD_TEST)->value('id'),
            'record_date' => $this->faker->dateTimeBetween('-6 months', 'now'),
            'metric_key' => 'hb_g_dl',
            'metric_value' => $this->faker->randomFloat(1, 12.5, 17.5),
            'unit' => 'g/dL',
            'ref_low' => 13.0,
            'ref_high' => 17.5,
            'flag' => RecordMetric::FLAG_NORMAL,
        ];
    }

    public function ferritin(?float $value = null): static
    {
        return $this->state(function () use ($value): array {
            $v = $value ?? $this->faker->numberBetween(20, 280);

            return [
                'metric_key' => 'ferritin_ng_ml',
                'metric_value' => $v,
                'unit' => 'ng/mL',
                'ref_low' => 22.0,
                'ref_high' => 322.0,
                'flag' => $v < 30 ? RecordMetric::FLAG_LOW : RecordMetric::FLAG_NORMAL,
            ];
        });
    }

    public function vitaminD(?float $value = null): static
    {
        return $this->state(function () use ($value): array {
            $v = $value ?? $this->faker->randomFloat(1, 15, 55);
            $flag = match (true) {
                $v < 20 => RecordMetric::FLAG_CRITICAL,
                $v < 30 => RecordMetric::FLAG_LOW,
                default => RecordMetric::FLAG_NORMAL,
            };

            return [
                'metric_key' => 'vit_d_ng_ml',
                'metric_value' => $v,
                'unit' => 'ng/mL',
                'ref_low' => 30.0,
                'ref_high' => 100.0,
                'flag' => $flag,
            ];
        });
    }

    public function bodyFat(?float $value = null): static
    {
        return $this->state(function () use ($value): array {
            $v = $value ?? $this->faker->randomFloat(1, 8, 18);

            return [
                'category_id' => RecordCategory::where('slug', RecordCategory::INBODY)->value('id'),
                'metric_key' => 'body_fat_pct',
                'metric_value' => $v,
                'unit' => '%',
                'ref_low' => 6.0,
                'ref_high' => 18.0,
                'flag' => $v > 18 ? RecordMetric::FLAG_HIGH : RecordMetric::FLAG_NORMAL,
            ];
        });
    }

    public function flagged(string $severity): static
    {
        return $this->state(fn (): array => ['flag' => $severity]);
    }
}
