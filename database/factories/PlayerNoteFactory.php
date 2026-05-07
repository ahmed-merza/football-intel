<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Player;
use App\Models\PlayerNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlayerNote>
 */
class PlayerNoteFactory extends Factory
{
    protected $model = PlayerNote::class;

    private const SAMPLE_NOTES = [
        'Followed up on recent blood test — player to retest in 8 weeks.',
        'Switched nutrition plan to higher-carb variant after GPS data showed second-half fatigue.',
        'Player reports better sleep after adding magnesium to evening routine.',
        'Coach flagged inconsistency in match-day fueling — reviewed pre-match protocol.',
        'Skin-fold check next Thursday.',
        'Discussed supplement stack; cycling off caffeine for 2 weeks.',
    ];

    public function definition(): array
    {
        return [
            'player_id' => Player::factory(),
            'record_id' => null,
            'body' => $this->faker->randomElement(self::SAMPLE_NOTES),
            'created_by' => User::factory(),
        ];
    }
}
