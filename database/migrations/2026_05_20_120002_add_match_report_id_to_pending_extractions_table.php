<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Match-report extraction also needs the async-callback safety net, but
 * the owning row is a match_reports record — not a player_records or
 * nutritionist_analyses one. Add a third sibling FK so the webhook +
 * reaper can resolve the right owner by `kind`:
 *
 *   blood_test / inbody / nutrition_plan / classifier → record_id
 *   nutritionist_analysis                             → analysis_id
 *   match_report                                      → match_report_id
 *
 * Mutually exclusive in practice — the gateway is the single writer and
 * only sets one — but no DB-level check (keeps inserts simple).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_extractions', function (Blueprint $table): void {
            $table->foreignId('match_report_id')
                ->nullable()
                ->after('analysis_id')
                ->constrained('match_reports')
                ->cascadeOnDelete();

            $table->index(['match_report_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('pending_extractions', function (Blueprint $table): void {
            $table->dropIndex(['match_report_id', 'status']);
            $table->dropConstrainedForeignId('match_report_id');
        });
    }
};
