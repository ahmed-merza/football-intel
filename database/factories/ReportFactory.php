<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Player;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    protected $model = Report::class;

    public function definition(): array
    {
        return [
            'player_id' => Player::factory(),
            'type' => Report::TYPE_PLAYER,
            'title' => $this->faker->date('F Y').' — Player report',
            'period_from' => $this->faker->dateTimeBetween('-3 months', '-1 month'),
            'period_to' => $this->faker->dateTimeBetween('-1 month', 'now'),
            'storage_disk' => 'local',
            'storage_path' => 'reports/'.Str::uuid()->toString().'.pdf',
            'generated_by' => User::factory(),
            'meta' => [
                'sections' => ['summary', 'latest_data', 'trends', 'risk_flags', 'recommendations'],
                'narrative_model' => 'claude-opus-4-7',
                'kb_citations' => [],
            ],
        ];
    }

    public function team(): static
    {
        return $this->state(fn (): array => [
            'player_id' => null,
            'type' => Report::TYPE_TEAM,
            'title' => $this->faker->date('F Y').' — Team overview',
        ]);
    }
}
