<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Player;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Player>
 */
class PlayerFactory extends Factory
{
    protected $model = Player::class;

    private const POSITIONS = ['GK', 'CB', 'RB', 'LB', 'CDM', 'CM', 'CAM', 'LM', 'RM', 'LW', 'RW', 'ST'];

    private const BAHRAIN_CLUBS = [
        'Al-Riffa SC', 'Muharraq Club', 'Al-Hidd SCC', 'Al-Najma', 'Al-Ahli Manama',
        'Busaiteen Club', 'East Riffa', 'Manama Club', 'Al-Shabab SC', 'Sitra Club',
    ];

    private const ARABIC_FIRST_NAMES = [
        'عبدالرحمن', 'محمد', 'أحمد', 'علي', 'حسن', 'حسين', 'خالد', 'سعيد', 'كميل', 'يوسف',
    ];

    private const ARABIC_LAST_NAMES = [
        'المبارك', 'السليبيخ', 'الأسود', 'العبدالله', 'الكوهجي', 'الصباغ', 'الدوسري', 'البوعينين',
    ];

    private const LATIN_FIRST_NAMES = [
        'Abdulrahman', 'Mohammed', 'Ahmed', 'Ali', 'Hassan', 'Hussain', 'Khalid', 'Saeed', 'Kameel', 'Yusuf',
    ];

    private const LATIN_LAST_NAMES = [
        'Mubarak', 'Selaibeekh', 'Al-Aswad', 'Al-Abdullah', 'Kohji', 'Al-Sabbagh', 'Al-Dosari', 'Al-Bouainain',
    ];

    public function definition(): array
    {
        $firstIdx = array_rand(self::LATIN_FIRST_NAMES);
        $lastIdx = array_rand(self::LATIN_LAST_NAMES);

        $position = $this->faker->randomElement(self::POSITIONS);

        // Goalkeepers are typically taller and heavier than outfielders
        $isGoalkeeper = $position === 'GK';
        $height = $isGoalkeeper ? $this->faker->numberBetween(185, 198) : $this->faker->numberBetween(170, 190);
        $weight = $isGoalkeeper ? $this->faker->numberBetween(78, 92) : $this->faker->numberBetween(65, 85);

        return [
            'full_name' => self::LATIN_FIRST_NAMES[$firstIdx].' '.self::LATIN_LAST_NAMES[$lastIdx],
            'name_ar' => self::ARABIC_FIRST_NAMES[$firstIdx].' '.self::ARABIC_LAST_NAMES[$lastIdx],
            'club' => $this->faker->randomElement(self::BAHRAIN_CLUBS),
            'position' => $position,
            'date_of_birth' => $this->faker->dateTimeBetween('-34 years', '-17 years'),
            'nationality' => 'Bahraini',
            'height_cm' => $height,
            'weight_kg' => $weight + $this->faker->randomFloat(1, 0, 0.9),
            'preferred_foot' => $this->faker->randomElement(['right', 'right', 'right', 'left', 'both']),
            'player_code' => 'SC-'.$this->faker->numberBetween(10000, 99999),
            'phone' => '+973'.$this->faker->numberBetween(30000000, 39999999),
            'email' => null,
            'photo_path' => null,
            'status' => Player::STATUS_ACTIVE,
        ];
    }

    public function goalkeeper(): static
    {
        return $this->state(fn (): array => [
            'position' => 'GK',
            'height_cm' => $this->faker->numberBetween(185, 198),
            'weight_kg' => $this->faker->numberBetween(78, 92),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['status' => Player::STATUS_INACTIVE]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['status' => Player::STATUS_ARCHIVED]);
    }
}
