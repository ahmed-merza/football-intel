<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add the `match_performance` slug to the record_categories enum so
 * resolved per-player match data slots into the existing PlayerRecord
 * pipeline (timeline, metric fanner, AI agent context) the same way
 * blood / inbody / nutrition_plan rows do.
 *
 * Kept distinct from `match_activity` (which was seeded in the initial
 * categories migration for future GPS/wearable-derived match-day data):
 *   - match_activity     → distance, sprints, max HR, training load (GPS source)
 *   - match_performance  → rating, passes, tackles, duels, take-ons (event-data source)
 *
 * Both can coexist on the same player on the same date if both source
 * streams are uploaded.
 *
 * updateOrInsert keeps the migration idempotent on re-run (e.g. after a
 * down() in dev).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('record_categories')->updateOrInsert(
            ['slug' => 'match_performance'],
            [
                'label_en' => 'Match performance',
                'label_ar' => 'أداء المباراة',
                'description' => 'Per-player match stats from event-data reports (rating, passes, tackles, duels)',
                'sort_order' => 8,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    public function down(): void
    {
        DB::table('record_categories')->where('slug', 'match_performance')->delete();
    }
};
