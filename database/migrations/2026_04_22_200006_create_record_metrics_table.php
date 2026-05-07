<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalised metric fan-out. One row per extracted value (e.g. Hb, ferritin,
 * body_fat_pct, session_distance_m). `player_id`, `record_date`, and `category_id`
 * are duplicated from `player_records` on purpose — the hot chart query
 * ("last 12 months of Hb for player X") then never needs a join.
 *
 * Kept in sync by an observer on PlayerRecord that re-fans the metric rows
 * whenever `extracted` changes. Soft deletes on to match the parent record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_metrics', function (Blueprint $table) {
            $table->id();

            $table->foreignId('record_id')
                ->constrained('player_records')->cascadeOnDelete();

            // Denormalised — pointer to the same player as the parent record
            $table->foreignId('player_id')
                ->constrained('players')->cascadeOnDelete();

            $table->foreignId('category_id')
                ->constrained('record_categories')->restrictOnDelete();

            $table->date('record_date');

            // See docs/database.md §Metric key registry for the canonical list
            $table->string('metric_key', 60);

            $table->decimal('metric_value', 14, 4);
            $table->string('unit', 30)->nullable();
            $table->decimal('ref_low', 14, 4)->nullable();
            $table->decimal('ref_high', 14, 4)->nullable();

            // low | normal | high | critical
            $table->string('flag', 10)->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            // Primary: trend chart for one player on one metric over time
            $table->index(['player_id', 'metric_key', 'record_date'], 'record_metrics_trend_idx');

            // Secondary: aggregate across the squad for one metric
            $table->index(['metric_key', 'record_date']);

            $table->index('record_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_metrics');
    }
};
