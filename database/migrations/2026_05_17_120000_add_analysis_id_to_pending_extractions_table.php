<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NutritionistAssistant calls also need the async-callback safety net,
 * but they own a nutritionist_analyses row, not a player_records one.
 * Add a sibling FK so the webhook + reaper can resolve the right owner
 * by kind — extraction kinds dispatch through record_id, the
 * nutritionist_analysis kind dispatches through analysis_id. Mutually
 * exclusive in practice, but no DB check (keeps inserts simple — the
 * gateway is the single writer and only sets one).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_extractions', function (Blueprint $table): void {
            $table->foreignId('analysis_id')
                ->nullable()
                ->after('record_id')
                ->constrained('nutritionist_analyses')
                ->cascadeOnDelete();

            $table->index(['analysis_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('pending_extractions', function (Blueprint $table): void {
            $table->dropIndex(['analysis_id', 'status']);
            $table->dropConstrainedForeignId('analysis_id');
        });
    }
};
