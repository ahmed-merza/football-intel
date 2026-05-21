<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 follow-up: per-player pass breakdown (by area × direction × length).
 *
 * The data is in the same Player Stats table the main MatchReportExtractor
 * already reads (Distribution Stats columns on pages 49-52), we just didn't
 * ask for it in the JSON schema until now. Stored as a single JSON column
 * rather than 18 typed columns — it's a fixed-shape nested object that the
 * UI renders as a whole, not a target for ad-hoc SQL filtering.
 *
 * Shape:
 *   {
 *     "by_area":      { "defensive_third": {succeeded, total}, "middle_third": {…}, "final_third": {…} },
 *     "by_direction": { "forward": {…},  "sideways": {…},     "backward": {…} },
 *     "by_length":    { "short": {…},    "medium": {…},       "long": {…} }
 *   }
 *
 * Nullable so existing rows (extracted before this schema change) stay valid
 * — they'll back-fill on re-extract.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isPg = DB::connection()->getDriverName() === 'pgsql';
        $jsonType = $isPg ? 'jsonb' : 'json';

        Schema::table('match_performances', function (Blueprint $table) use ($jsonType): void {
            $table->{$jsonType}('pass_breakdown')->nullable()->after('controls_under_pressure');
        });
    }

    public function down(): void
    {
        Schema::table('match_performances', function (Blueprint $table): void {
            $table->dropColumn('pass_breakdown');
        });
    }
};
