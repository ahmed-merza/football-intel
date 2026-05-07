<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Player;
use App\Models\PlayerRecord;
use App\Models\RecordCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Produces realistic per-category extracted JSON so timeline charts render
 * with plausible shapes in dev/test. Use the state methods (`bloodTest()`,
 * `inbody()`, etc.) to pick a specific category; the default is `blood_test`.
 *
 * @extends Factory<PlayerRecord>
 */
class PlayerRecordFactory extends Factory
{
    protected $model = PlayerRecord::class;

    public function definition(): array
    {
        return [
            'player_id' => Player::factory(),
            'category_id' => fn (): int => RecordCategory::where('slug', RecordCategory::BLOOD_TEST)->value('id'),
            'record_date' => $this->faker->dateTimeBetween('-6 months', 'now'),
            'submission_id' => null,
            'primary_attachment_id' => null,
            'extracted' => $this->bloodTestPayload(),
            'analysis' => null,
            'analysis_generated_at' => null,
            'summary_text' => null,
            'source_lab' => $this->faker->randomElement(['HICARE Medical Centre', 'Al Kindi Hospital', 'Bahrain Specialist Hospital']),
            'reviewed' => false,
            'reviewed_at' => null,
            'reviewed_by' => null,
            'admin_notes' => null,
        ];
    }

    public function bloodTest(): static
    {
        return $this->state(fn (): array => [
            'category_id' => RecordCategory::where('slug', RecordCategory::BLOOD_TEST)->value('id'),
            'extracted' => $this->bloodTestPayload(),
        ]);
    }

    public function inbody(): static
    {
        return $this->state(fn (): array => [
            'category_id' => RecordCategory::where('slug', RecordCategory::INBODY)->value('id'),
            'extracted' => $this->inbodyPayload(),
            'source_lab' => 'InBody 770',
        ]);
    }

    public function gpsWearable(): static
    {
        return $this->state(fn (): array => [
            'category_id' => RecordCategory::where('slug', RecordCategory::GPS_WEARABLE)->value('id'),
            'extracted' => [
                'device' => $this->faker->randomElement(['Garmin', 'Polar', 'WHOOP', 'Catapult', 'StatSports']),
                'session_type' => $this->faker->randomElement(['training', 'match', 'recovery']),
                'date' => $this->faker->date(),
                'duration_min' => $this->faker->numberBetween(45, 105),
                'total_distance_m' => $this->faker->numberBetween(5000, 12_000),
                'high_speed_runs' => $this->faker->numberBetween(10, 40),
                'sprints' => $this->faker->numberBetween(5, 25),
                'max_hr' => $this->faker->numberBetween(175, 200),
                'avg_hr' => $this->faker->numberBetween(130, 165),
                'training_load' => $this->faker->numberBetween(250, 900),
            ],
            'source_lab' => null,
        ]);
    }

    public function nutritionPlan(): static
    {
        return $this->state(fn (): array => [
            'category_id' => RecordCategory::where('slug', RecordCategory::NUTRITION_PLAN)->value('id'),
            'extracted' => $this->nutritionPlanPayload(),
            'source_lab' => 'Right Calories Sports Nutrition',
        ]);
    }

    public function reviewed(): static
    {
        return $this->state(fn (): array => [
            'reviewed' => true,
            'reviewed_at' => $this->faker->dateTimeBetween('-2 months', 'now'),
        ]);
    }

    /** @return array<string, mixed> */
    private function nutritionPlanPayload(): array
    {
        $kcal = $this->faker->numberBetween(2400, 3200);
        $protein = $this->faker->numberBetween(120, 180);
        $carb = $this->faker->numberBetween(300, 450);
        $fat = $this->faker->numberBetween(70, 100);
        $water = $this->faker->randomFloat(1, 3.0, 4.5);

        return [
            'plan_name' => 'Right Calories Performance Plan',
            'patient_name_on_report' => $this->faker->name(),
            'plan_start_date' => $this->faker->date(),
            'plan_end_date' => null,
            'metrics' => [
                ['name' => 'Daily energy',  'key' => 'daily_kcal',      'value' => $kcal,    'unit' => 'kcal', 'ref_low' => null, 'ref_high' => null, 'flag' => 'normal'],
                ['name' => 'Total protein', 'key' => 'daily_protein_g', 'value' => $protein, 'unit' => 'g',    'ref_low' => null, 'ref_high' => null, 'flag' => 'normal'],
                ['name' => 'Total carbs',   'key' => 'daily_carb_g',    'value' => $carb,    'unit' => 'g',    'ref_low' => null, 'ref_high' => null, 'flag' => 'normal'],
                ['name' => 'Total fats',    'key' => 'daily_fat_g',     'value' => $fat,     'unit' => 'g',    'ref_low' => null, 'ref_high' => null, 'flag' => 'normal'],
                ['name' => 'Hydration',     'key' => 'daily_water_l',   'value' => $water,   'unit' => 'L',    'ref_low' => null, 'ref_high' => null, 'flag' => 'normal'],
            ],
            'meals' => [
                ['name' => 'Breakfast', 'time' => '07:30', 'items' => [
                    ['food' => 'Oats with honey', 'grams' => 80, 'protein_g' => 11, 'carb_g' => 56, 'fat_g' => 6, 'kcal' => 320],
                    ['food' => 'Eggs', 'grams' => 100, 'protein_g' => 13, 'carb_g' => 1, 'fat_g' => 11, 'kcal' => 155],
                ]],
                ['name' => 'Pre-training', 'time' => '15:30', 'items' => [
                    ['food' => 'Banana', 'grams' => 120, 'protein_g' => 1, 'carb_g' => 27, 'fat_g' => 0, 'kcal' => 105],
                ]],
                ['name' => 'Dinner', 'time' => '19:30', 'items' => [
                    ['food' => 'Chicken breast', 'grams' => 200, 'protein_g' => 46, 'carb_g' => 0, 'fat_g' => 4, 'kcal' => 220],
                    ['food' => 'Brown rice', 'grams' => 150, 'protein_g' => 4, 'carb_g' => 36, 'fat_g' => 1, 'kcal' => 165],
                ]],
            ],
            'supplements' => [
                ['name' => 'Whey isolate', 'dose' => '30 g', 'timing' => 'post-training', 'purpose' => 'muscle recovery'],
                ['name' => 'Vitamin D3 + K2', 'dose' => '5000 IU', 'timing' => 'with breakfast', 'purpose' => 'bone + immune'],
            ],
            'notes' => 'Adjust portions ±10% based on training load on the day.',
        ];
    }

    /** @return array<string, mixed> */
    private function inbodyPayload(): array
    {
        $weight = $this->faker->randomFloat(1, 65, 90);
        $bfPct = $this->faker->randomFloat(1, 8, 18);
        $bfKg = round($weight * $bfPct / 100, 1);
        $smm = $this->faker->randomFloat(1, 28, 40);

        return [
            'patient_name_on_report' => $this->faker->name(),
            'test_date' => $this->faker->date(),
            'device' => 'InBody 770',
            'metrics' => [
                ['name' => 'Weight',                'key' => 'weight_kg',          'value' => $weight, 'unit' => 'kg',  'ref_low' => 60.0, 'ref_high' => 95.0, 'flag' => 'normal'],
                ['name' => 'Skeletal Muscle Mass',  'key' => 'skeletal_muscle_kg', 'value' => $smm,    'unit' => 'kg',  'ref_low' => 30.0, 'ref_high' => 40.0, 'flag' => 'normal'],
                ['name' => 'Percent Body Fat',      'key' => 'body_fat_pct',       'value' => $bfPct,  'unit' => '%',   'ref_low' => 10.0, 'ref_high' => 20.0, 'flag' => 'normal'],
                ['name' => 'Body Fat Mass',         'key' => 'body_fat_kg',        'value' => $bfKg,   'unit' => 'kg',  'ref_low' => null, 'ref_high' => null, 'flag' => 'normal'],
                ['name' => 'Visceral Fat Level',    'key' => 'visceral_fat',       'value' => $this->faker->numberBetween(2, 10),       'unit' => 'lvl', 'ref_low' => null, 'ref_high' => 10.0, 'flag' => 'normal'],
                ['name' => 'Phase Angle',           'key' => 'phase_angle',        'value' => $this->faker->randomFloat(1, 6.0, 8.5),   'unit' => 'deg', 'ref_low' => 5.0,  'ref_high' => 9.0,  'flag' => 'normal'],
            ],
            'segmental' => [
                'right_arm_kg' => $this->faker->randomFloat(2, 2.8, 4.2),
                'left_arm_kg' => $this->faker->randomFloat(2, 2.8, 4.2),
                'trunk_kg' => $this->faker->randomFloat(2, 22.0, 32.0),
                'right_leg_kg' => $this->faker->randomFloat(2, 8.5, 12.0),
                'left_leg_kg' => $this->faker->randomFloat(2, 8.5, 12.0),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function bloodTestPayload(): array
    {
        return [
            'patient_name_on_report' => $this->faker->name(),
            'sample_date' => $this->faker->date(),
            'lab_name' => 'HICARE Medical Centre',
            'labs' => [
                ['name' => 'Haemoglobin',     'value' => $this->faker->randomFloat(1, 12.5, 17.5), 'unit' => 'g/dL',    'ref_low' => 13.0, 'ref_high' => 17.5, 'flag' => 'normal'],
                ['name' => 'Ferritin',        'value' => $this->faker->numberBetween(20, 280),      'unit' => 'ng/mL',   'ref_low' => 22.0, 'ref_high' => 322.0, 'flag' => 'normal'],
                ['name' => 'Vitamin D (25-OH)', 'value' => $this->faker->randomFloat(1, 15, 55),     'unit' => 'ng/mL',   'ref_low' => 30.0, 'ref_high' => 100.0, 'flag' => 'normal'],
                ['name' => 'Sodium',          'value' => $this->faker->numberBetween(136, 145),     'unit' => 'mmol/L',  'ref_low' => 136.0, 'ref_high' => 145.0, 'flag' => 'normal'],
                ['name' => 'Potassium',       'value' => $this->faker->randomFloat(2, 3.6, 5.0),    'unit' => 'mmol/L',  'ref_low' => 3.5,  'ref_high' => 5.1,   'flag' => 'normal'],
            ],
        ];
    }
}
