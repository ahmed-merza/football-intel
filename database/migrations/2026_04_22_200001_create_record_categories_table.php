<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Static reference table — one row per document category the system recognises.
 * Seeded in the same migration so the enum is version-controlled with the schema.
 * NOT soft-deletable: categories are effectively an enum and the FK from
 * player_records relies on them existing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 50)->unique();
            $table->string('label_en', 100);
            $table->string('label_ar', 100);
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz();
        });

        // Seed inline — these IDs are referenced across the application, so
        // they must exist the moment the table does.
        $now = now();
        DB::table('record_categories')->insert([
            ['slug' => 'blood_test',                 'label_en' => 'Blood test',               'label_ar' => 'تحليل الدم',       'description' => 'Lab panels — CBC, biochemistry, lipids, vitamins, iron, thyroid', 'sort_order' => 1,  'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'inbody',                     'label_en' => 'Body composition',         'label_ar' => 'تحليل الجسم',      'description' => 'InBody / DEXA / bioimpedance tests',                               'sort_order' => 2,  'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'gps_wearable',               'label_en' => 'GPS / wearable',           'label_ar' => 'تتبع الأداء',      'description' => 'Garmin, Polar, WHOOP, Catapult, StatSports session exports',       'sort_order' => 3,  'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'nutrition_plan',             'label_en' => 'Nutrition plan',           'label_ar' => 'خطة التغذية',      'description' => 'Personalised meal plan with daily targets',                         'sort_order' => 4,  'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'hydration_supplement_plan',  'label_en' => 'Hydration & supplements',  'label_ar' => 'الترطيب والمكملات', 'description' => 'Hydration protocol + supplement stack',                            'sort_order' => 5,  'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'coach_feedback',             'label_en' => 'Coach feedback',           'label_ar' => 'ملاحظات المدرب',    'description' => 'Free-text feedback or voice notes from coaching/medical staff',   'sort_order' => 6,  'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'match_activity',             'label_en' => 'Match activity',           'label_ar' => 'نشاط المباراة',    'description' => 'Match-day load, minutes, distance, sprints, goals',                'sort_order' => 7,  'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'other',                      'label_en' => 'Other',                    'label_ar' => 'أخرى',             'description' => 'Uncategorised — classifier fallback',                              'sort_order' => 99, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('record_categories');
    }
};
