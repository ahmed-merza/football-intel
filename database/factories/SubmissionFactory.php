<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Player;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Submission>
 */
class SubmissionFactory extends Factory
{
    protected $model = Submission::class;

    public function definition(): array
    {
        return [
            'channel' => Submission::CHANNEL_MANUAL_UPLOAD,
            'player_id' => Player::factory(),
            'uploaded_by' => User::factory(),
            'received_at' => $this->faker->dateTimeBetween('-3 months', 'now'),
            'status' => Submission::STATUS_CLASSIFIED,
            'processed_at' => $this->faker->dateTimeBetween('-3 months', 'now'),
            'notes' => null,
        ];
    }

    public function processing(): static
    {
        return $this->state(fn (): array => [
            'status' => Submission::STATUS_PROCESSING,
            'processed_at' => null,
        ]);
    }

    public function needsReview(): static
    {
        return $this->state(fn (): array => [
            'status' => Submission::STATUS_NEEDS_REVIEW,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => Submission::STATUS_FAILED,
        ]);
    }
}
