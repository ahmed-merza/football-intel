<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 (option C): match-level facts that sit naturally on the
 * existing match_reports row rather than in a sibling table.
 *
 * Possession is genuinely new data — not derivable from the per-player
 * rows we capture today (no per-player possession field). Half-time
 * scores are useful match-level context that we currently squash into
 * the single final score columns.
 *
 * All columns nullable because:
 *   - older reports already in the DB don't have them populated
 *   - the extractor may not always emit them (defensive)
 *   - non-AGCFF report formats may not provide them
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('match_reports', function (Blueprint $table): void {
            // 0.00 - 100.00. Two teams sum to ~100% per match (the report
            // sometimes rounds so 57.4 + 42.6 = 100.0 exactly).
            $table->decimal('home_possession_pct', 5, 2)->nullable()->after('away_score');
            $table->decimal('away_possession_pct', 5, 2)->nullable()->after('home_possession_pct');

            // Half-time scores. The report breaks the goal column into
            // "0' - 45'" + "45' - 90'" — sum should equal final score.
            $table->unsignedTinyInteger('first_half_home_score')->nullable()->after('away_possession_pct');
            $table->unsignedTinyInteger('first_half_away_score')->nullable()->after('first_half_home_score');
            $table->unsignedTinyInteger('second_half_home_score')->nullable()->after('first_half_away_score');
            $table->unsignedTinyInteger('second_half_away_score')->nullable()->after('second_half_home_score');
        });
    }

    public function down(): void
    {
        Schema::table('match_reports', function (Blueprint $table): void {
            $table->dropColumn([
                'home_possession_pct',
                'away_possession_pct',
                'first_half_home_score',
                'first_half_away_score',
                'second_half_home_score',
                'second_half_away_score',
            ]);
        });
    }
};
