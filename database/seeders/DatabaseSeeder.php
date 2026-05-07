<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Alert;
use App\Models\Attachment;
use App\Models\KnowledgeDocument;
use App\Models\Player;
use App\Models\PlayerNote;
use App\Models\PlayerRecord;
use App\Models\RecordCategory;
use App\Models\RecordMetric;
use App\Models\Submission;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Dev seed — admin + 3 archetype players with ~3 months of realistic history
 * (blood, body comp, GPS, match activity) plus current nutrition + hydration
 * plans, coach feedback, notes, and a handful of open alerts. Run via
 * `php artisan db:seed` after a fresh migrate so the dashboard has something
 * plausible to render. Tests don't rely on this — they build exactly what
 * they need via factories.
 */
class DatabaseSeeder extends Seeder
{
    /** @var array<string, RecordCategory> */
    private array $categories = [];

    public function run(): void
    {
        $this->categories = RecordCategory::all()->keyBy('slug')->all();

        $admin = $this->seedAdmin();

        $midfielder = $this->seedMidfielder();
        $defender = $this->seedDefender();
        $goalkeeper = $this->seedGoalkeeper();

        $this->seedHistory($midfielder, $admin, profile: 'midfielder');
        $this->seedHistory($defender, $admin, profile: 'defender');
        $this->seedHistory($goalkeeper, $admin, profile: 'goalkeeper');

        $this->seedCurrentPlans($midfielder, $admin);
        $this->seedCurrentPlans($defender, $admin);
        $this->seedCurrentPlans($goalkeeper, $admin);

        $this->seedAlerts($midfielder, $defender, $goalkeeper, $admin);
        $this->seedNotes([$midfielder, $defender, $goalkeeper], $admin);
        $this->seedKnowledge($admin);
    }

    private function seedAdmin(): User
    {
        return User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Dr. Fadhel Al-Sabbagh',
                'password' => Hash::make('password'),
            ],
        );
    }

    private function seedMidfielder(): Player
    {
        return Player::factory()->create([
            'full_name' => 'Abdulrahman Mubarak',
            'name_ar' => 'عبدالرحمن المبارك',
            'club' => 'Al-Riffa SC',
            'position' => 'CM',
            'date_of_birth' => CarbonImmutable::now()->subYears(24)->toDateString(),
            'height_cm' => 178,
            'weight_kg' => 72.5,
            'preferred_foot' => 'right',
            'player_code' => 'SC-10001',
            'phone' => '+97333221100',
        ]);
    }

    private function seedDefender(): Player
    {
        return Player::factory()->create([
            'full_name' => 'Hassan Al-Aswad',
            'name_ar' => 'حسن الأسود',
            'club' => 'Muharraq Club',
            'position' => 'CB',
            'date_of_birth' => CarbonImmutable::now()->subYears(27)->toDateString(),
            'height_cm' => 186,
            'weight_kg' => 82.0,
            'preferred_foot' => 'left',
            'player_code' => 'SC-10002',
            'phone' => '+97333445566',
        ]);
    }

    private function seedGoalkeeper(): Player
    {
        return Player::factory()->goalkeeper()->create([
            'full_name' => 'Yusuf Kohji',
            'name_ar' => 'يوسف الكوهجي',
            'club' => 'Al-Hidd SCC',
            'position' => 'GK',
            'date_of_birth' => CarbonImmutable::now()->subYears(29)->toDateString(),
            'height_cm' => 192,
            'weight_kg' => 86.0,
            'preferred_foot' => 'right',
            'player_code' => 'SC-10003',
            'phone' => '+97333778899',
        ]);
    }

    /**
     * Three months of history (90 / 60 / 30 days ago), each month yielding:
     * 1 submission → 1 attachment → blood test, InBody, 2 GPS sessions,
     * 1 match activity. Values drift per-month to produce visible trends.
     */
    private function seedHistory(Player $player, User $admin, string $profile): void
    {
        foreach ([90, 60, 30] as $monthIndex => $daysAgo) {
            $date = CarbonImmutable::now()->subDays($daysAgo)->startOfDay();
            $submission = $this->submissionFor($player, $admin, $date);
            $this->attachmentFor($submission, "batch_{$date->format('Y_m')}.pdf");

            $this->bloodRecord($player, $submission, $date, $profile, $monthIndex);
            $this->inbodyRecord($player, $submission, $date->addDay(), $profile, $monthIndex);
            $this->gpsRecord($player, $submission, $date->addDays(3), 'training');
            $this->gpsRecord($player, $submission, $date->addDays(10), 'training');
            $this->matchRecord($player, $submission, $date->addDays(14));
        }
    }

    private function submissionFor(Player $player, User $admin, CarbonImmutable $receivedAt): Submission
    {
        return Submission::factory()->create([
            'player_id' => $player->id,
            'uploaded_by' => $admin->id,
            'received_at' => $receivedAt,
            'status' => Submission::STATUS_CLASSIFIED,
            'processed_at' => $receivedAt->addMinutes(5),
        ]);
    }

    private function attachmentFor(Submission $submission, string $filename): Attachment
    {
        $month = CarbonImmutable::parse($submission->received_at)->format('Y-m');

        return Attachment::factory()->create([
            'submission_id' => $submission->id,
            'original_filename' => $filename,
            'storage_path' => "attachments/{$submission->player_id}/{$month}/".Str::uuid()->toString().'.pdf',
        ]);
    }

    private function bloodRecord(Player $player, Submission $submission, CarbonImmutable $date, string $profile, int $monthIndex): PlayerRecord
    {
        [$hb, $ferritin, $vitD] = $this->bloodProfile($profile, $monthIndex);
        $category = $this->categories[RecordCategory::BLOOD_TEST];

        $record = PlayerRecord::create([
            'player_id' => $player->id,
            'category_id' => $category->id,
            'record_date' => $date->toDateString(),
            'submission_id' => $submission->id,
            'source_lab' => 'HICARE Medical Centre',
            'reviewed' => true,
            'reviewed_at' => $date->addDay(),
            'reviewed_by' => $submission->uploaded_by,
            'extracted' => [
                'patient_name_on_report' => $player->full_name,
                'sample_date' => $date->toDateString(),
                'lab_name' => 'HICARE Medical Centre',
                'labs' => [
                    ['name' => 'Haemoglobin',       'value' => $hb,       'unit' => 'g/dL',   'ref_low' => 13.0,  'ref_high' => 17.5,  'flag' => 'normal'],
                    ['name' => 'Ferritin',          'value' => $ferritin, 'unit' => 'ng/mL',  'ref_low' => 22.0,  'ref_high' => 322.0, 'flag' => $ferritin < 30 ? 'low' : 'normal'],
                    ['name' => 'Vitamin D (25-OH)', 'value' => $vitD,     'unit' => 'ng/mL',  'ref_low' => 30.0,  'ref_high' => 100.0, 'flag' => $this->vitaminDFlag($vitD)],
                    ['name' => 'Sodium',            'value' => 140,       'unit' => 'mmol/L', 'ref_low' => 136.0, 'ref_high' => 145.0, 'flag' => 'normal'],
                    ['name' => 'Potassium',        'value' => 4.2,       'unit' => 'mmol/L', 'ref_low' => 3.5,   'ref_high' => 5.1,   'flag' => 'normal'],
                ],
            ],
        ]);

        $this->fanOutMetrics($record, $category, [
            ['hb_g_dl',          $hb,       'g/dL',   13.0,  17.5,  'normal'],
            ['ferritin_ng_ml',   $ferritin, 'ng/mL',  22.0,  322.0, $ferritin < 30 ? RecordMetric::FLAG_LOW : RecordMetric::FLAG_NORMAL],
            ['vit_d_ng_ml',      $vitD,     'ng/mL',  30.0,  100.0, $this->vitaminDFlag($vitD)],
            ['sodium_mmol_l',    140.0,     'mmol/L', 136.0, 145.0, RecordMetric::FLAG_NORMAL],
            ['potassium_mmol_l', 4.2,       'mmol/L', 3.5,   5.1,   RecordMetric::FLAG_NORMAL],
        ]);

        return $record;
    }

    /** @return array{float, float, float} */
    private function bloodProfile(string $profile, int $monthIndex): array
    {
        return match ($profile) {
            // Midfielder — ferritin trending down (low in recent month → warn alert)
            'midfielder' => [[14.2, 48.0, 32.0], [14.5, 42.0, 28.0], [14.3, 28.0, 30.0]][$monthIndex],
            // Defender — chronically low Vit D (critical alert throughout)
            'defender' => [[13.8, 80.0, 18.0], [13.5, 85.0, 17.0], [13.9, 78.0, 16.0]][$monthIndex],
            // Goalkeeper — healthy baseline
            'goalkeeper' => [[14.8, 140.0, 38.0], [14.6, 150.0, 40.0], [15.1, 145.0, 42.0]][$monthIndex],
            default => [14.0, 80.0, 35.0],
        };
    }

    private function vitaminDFlag(float $value): string
    {
        return match (true) {
            $value < 20 => RecordMetric::FLAG_CRITICAL,
            $value < 30 => RecordMetric::FLAG_LOW,
            default => RecordMetric::FLAG_NORMAL,
        };
    }

    private function inbodyRecord(Player $player, Submission $submission, CarbonImmutable $date, string $profile, int $monthIndex): PlayerRecord
    {
        [$weight, $bodyFat, $muscle] = $this->bodyProfile($profile, $monthIndex);
        $category = $this->categories[RecordCategory::INBODY];

        $record = PlayerRecord::create([
            'player_id' => $player->id,
            'category_id' => $category->id,
            'record_date' => $date->toDateString(),
            'submission_id' => $submission->id,
            'source_lab' => 'InBody Clinic',
            'reviewed' => true,
            'reviewed_at' => $date->addDay(),
            'reviewed_by' => $submission->uploaded_by,
            'extracted' => [
                'test_date' => $date->toDateString(),
                'weight_kg' => $weight,
                'body_fat_pct' => $bodyFat,
                'body_fat_kg' => round($weight * ($bodyFat / 100), 1),
                'skeletal_muscle_kg' => $muscle,
                'visceral_fat' => 5,
                'phase_angle' => 7.2,
            ],
        ]);

        $this->fanOutMetrics($record, $category, [
            ['weight_kg',          $weight,  'kg', null, null, RecordMetric::FLAG_NORMAL],
            ['body_fat_pct',       $bodyFat, '%',  6.0,  18.0, $bodyFat > 18 ? RecordMetric::FLAG_HIGH : RecordMetric::FLAG_NORMAL],
            ['skeletal_muscle_kg', $muscle,  'kg', null, null, RecordMetric::FLAG_NORMAL],
        ]);

        return $record;
    }

    /** @return array{float, float, float} */
    private function bodyProfile(string $profile, int $monthIndex): array
    {
        return match ($profile) {
            'midfielder' => [[72.5, 11.2, 33.8], [72.1, 11.0, 34.1], [72.8, 11.4, 34.0]][$monthIndex],
            'defender' => [[82.0, 13.5, 37.2], [82.4, 13.7, 37.1], [82.1, 13.6, 37.4]][$monthIndex],
            'goalkeeper' => [[86.0, 14.8, 38.5], [85.8, 14.6, 38.8], [86.2, 15.0, 38.6]][$monthIndex],
            default => [78.0, 13.0, 35.0],
        };
    }

    private function gpsRecord(Player $player, Submission $submission, CarbonImmutable $date, string $sessionType): PlayerRecord
    {
        return PlayerRecord::factory()->gpsWearable()->create([
            'player_id' => $player->id,
            'submission_id' => $submission->id,
            'record_date' => $date->toDateString(),
            'extracted' => [
                'device' => 'Catapult',
                'session_type' => $sessionType,
                'date' => $date->toDateString(),
                'duration_min' => 75,
                'total_distance_m' => 9200,
                'high_speed_runs' => 28,
                'sprints' => 14,
                'max_hr' => 188,
                'avg_hr' => 152,
                'training_load' => 560,
            ],
        ]);
    }

    private function matchRecord(Player $player, Submission $submission, CarbonImmutable $date): PlayerRecord
    {
        $category = $this->categories[RecordCategory::MATCH_ACTIVITY];

        return PlayerRecord::create([
            'player_id' => $player->id,
            'category_id' => $category->id,
            'record_date' => $date->toDateString(),
            'submission_id' => $submission->id,
            'reviewed' => true,
            'extracted' => [
                'match_date' => $date->toDateString(),
                'opponent' => 'Al-Najma',
                'minutes_played' => 90,
                'distance_m' => 10800,
                'sprints' => 18,
                'goals' => 0,
                'assists' => 1,
                'subjective_rating' => 7,
            ],
        ]);
    }

    private function seedCurrentPlans(Player $player, User $admin): void
    {
        $date = CarbonImmutable::now()->subDays(20);
        $submission = $this->submissionFor($player, $admin, $date);
        $this->attachmentFor($submission, 'current_plan.pdf');

        PlayerRecord::factory()->nutritionPlan()->create([
            'player_id' => $player->id,
            'submission_id' => $submission->id,
            'record_date' => $date->toDateString(),
            'reviewed' => true,
        ]);

        PlayerRecord::create([
            'player_id' => $player->id,
            'category_id' => $this->categories[RecordCategory::HYDRATION_SUPPLEMENT_PLAN]->id,
            'submission_id' => $submission->id,
            'record_date' => $date->toDateString(),
            'source_lab' => 'Right Calories Sports Nutrition',
            'reviewed' => true,
            'extracted' => [
                'items' => [
                    ['name' => 'Whey isolate',     'dose' => '30 g',    'timing' => 'post-training',  'purpose' => 'muscle recovery'],
                    ['name' => 'Vitamin D3 + K2',  'dose' => '5000 IU', 'timing' => 'with breakfast', 'purpose' => 'bone + immune'],
                    ['name' => 'Electrolyte mix',  'dose' => '1 sachet', 'timing' => 'pre-match',     'purpose' => 'hydration'],
                    ['name' => 'Iron bisglycinate', 'dose' => '25 mg',   'timing' => 'pre-breakfast', 'purpose' => 'ferritin repletion'],
                ],
                'notes' => 'Iron dose away from calcium/tea. Retest ferritin in 8 weeks.',
            ],
        ]);

        PlayerRecord::create([
            'player_id' => $player->id,
            'category_id' => $this->categories[RecordCategory::COACH_FEEDBACK]->id,
            'record_date' => CarbonImmutable::now()->subDays(7)->toDateString(),
            'reviewed' => true,
            'extracted' => [
                'session_date' => CarbonImmutable::now()->subDays(7)->toDateString(),
                'feedback_text' => 'Strong pressing work, covered the most ground of any midfielder in session. Recovery between intervals could improve.',
                'sentiment' => 'positive',
                'mentioned_issues' => ['interval_recovery'],
            ],
        ]);
    }

    /**
     * Low-ferritin warn for the midfielder (current), chronic low-Vit-D critical
     * for the defender (current + acknowledged history). Goalkeeper stays clean.
     */
    private function seedAlerts(Player $midfielder, Player $defender, Player $goalkeeper, User $admin): void
    {
        Alert::factory()->create([
            'player_id' => $midfielder->id,
            'severity' => Alert::SEVERITY_WARN,
            'kind' => 'iron_deficiency_risk',
            'message' => 'Ferritin dropped to 28 ng/mL — endurance risk in 2nd half. Iron bisglycinate started; retest in 8 weeks.',
        ]);

        Alert::factory()->create([
            'player_id' => $defender->id,
            'severity' => Alert::SEVERITY_CRITICAL,
            'kind' => 'low_vit_d',
            'message' => 'Vitamin D 16 ng/mL — critical. Supplement with D3 + K2 5000 IU/day; retest in 8 weeks.',
        ]);

        Alert::factory()->acknowledged()->create([
            'player_id' => $defender->id,
            'acknowledged_by' => $admin->id,
            'severity' => Alert::SEVERITY_WARN,
            'kind' => 'low_vit_d',
            'message' => 'Vitamin D 18 ng/mL — below athlete target. D3 supplement recommended.',
        ]);

        // Goalkeeper referenced for type completeness — no alerts by design.
        unset($goalkeeper);
    }

    /**
     * @param  list<Player>  $players
     */
    private function seedNotes(array $players, User $admin): void
    {
        foreach ($players as $player) {
            PlayerNote::factory()->count(2)->create([
                'player_id' => $player->id,
                'created_by' => $admin->id,
            ]);
        }
    }

    private function seedKnowledge(User $admin): void
    {
        KnowledgeDocument::factory()->supplementGuide()->create([
            'uploaded_by' => $admin->id,
            'metadata' => [
                'author' => 'Right Calories Sports Nutrition',
                'published_at' => '2024-09-01',
                'tags' => ['football', 'supplements', 'bahrain', 'national_team'],
            ],
        ]);

        KnowledgeDocument::factory()->nutritionPlan()->create([
            'uploaded_by' => $admin->id,
            'title' => 'Kameel Nutrition Plan v2',
            'metadata' => [
                'author' => 'Abdulla Selaibeekh',
                'published_at' => '2024-11-15',
                'tags' => ['football', 'nutrition', 'midfielder'],
            ],
        ]);
    }

    /**
     * @param  list<array{string, float, string, float|null, float|null, string}>  $metrics
     */
    private function fanOutMetrics(PlayerRecord $record, RecordCategory $category, array $metrics): void
    {
        foreach ($metrics as [$key, $value, $unit, $low, $high, $flag]) {
            RecordMetric::create([
                'record_id' => $record->id,
                'player_id' => $record->player_id,
                'category_id' => $category->id,
                'record_date' => $record->record_date,
                'metric_key' => $key,
                'metric_value' => $value,
                'unit' => $unit,
                'ref_low' => $low,
                'ref_high' => $high,
                'flag' => $flag,
            ]);
        }
    }
}
