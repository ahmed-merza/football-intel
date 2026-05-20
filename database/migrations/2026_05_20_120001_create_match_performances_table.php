<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-player rows extracted from a match report. One row per player who
 * appeared in the fixture (both Bahrain and opposition; opposition rows
 * carry team_side='opponent' and player_id=NULL — kept for team-level
 * stats + head-to-head context, never offered for resolution).
 *
 * Resolved Bahrain rows also produce a PlayerRecord (category=match_performance)
 * via MatchReportApplier so the existing timeline, metric fanner, and AI
 * agent infrastructure pick the data up without any new wiring. The link
 * is one-way: match_performances.player_record_id → player_records.id.
 *
 * Headline stats are flattened to columns (fast SQL aggregation, cheap
 * indexing, direct charting). Breakdowns — passes by area / direction /
 * length, average position coordinates per 15-min interval, event log
 * — live in raw_extracted JSON for low-query overhead.
 *
 * GK-only columns are nullable for outfield players (and vice versa is
 * implicit — outfield columns just sit at 0 for the GK row).
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        $isPg = $driver === 'pgsql';
        $jsonType = $isPg ? 'jsonb' : 'json';

        Schema::create('match_performances', function (Blueprint $table) use ($jsonType) {
            $table->id();

            $table->foreignId('match_report_id')
                ->constrained('match_reports')->cascadeOnDelete();

            // 'bahrain' | 'opponent'. String (not enum) for cross-DB simplicity.
            $table->string('team_side', 10);

            // Resolved player. NULL for opposition rows (always) and Bahrain rows
            // the admin couldn't resolve at apply time (anonymous trial player,
            // unknown number). Resolvable retroactively — apply re-runs idempotently.
            $table->foreignId('player_id')->nullable()
                ->constrained('players')->nullOnDelete();

            // Name as printed in the report (e.g. "Player 5" for anonymised rows,
            // "K. Alkhaldi" with whatever spelling the data provider used).
            // Retained even when player_id is set — disambiguates spelling drift
            // ("K. Alkhalaf" in one match, "K. Alkhaldi" in another).
            $table->string('reported_name', 255)->nullable();

            // Match-specific identity. jersey is the key signal for re-matching
            // anonymous "Player N" rows on subsequent uploads ("look up prior
            // match_performances with this jersey + team_side").
            $table->unsignedTinyInteger('jersey_number')->nullable();

            // GK/LB/CB/RB/CDM/CM/LW/RW/CF/LWB/RWB and friends. Match-specific —
            // a player listed as CM in players.position may play LW here. Keep
            // both columns; charts can pivot on either.
            $table->string('match_position', 10)->nullable();

            // 'starter' | 'sub' | 'unused'. Reports list named subs who never
            // came on (rating shown as "-"); those rows still get stored so the
            // team-side squad list stays complete.
            $table->string('appearance', 10);

            $table->unsignedSmallInteger('minute_on')->nullable();
            $table->unsignedSmallInteger('minute_off')->nullable();
            $table->unsignedSmallInteger('minutes_played')->nullable();

            // Wyscout-style editorial rating, 0.0–10.0. NULL for 'unused' subs.
            $table->decimal('rating', 3, 1)->nullable();

            // --- Offensive (outfield + GK alike) -----------------------------
            $table->unsignedSmallInteger('goals')->default(0);
            $table->unsignedSmallInteger('assists')->default(0);
            $table->unsignedSmallInteger('shots')->default(0);
            $table->unsignedSmallInteger('shots_on_target')->default(0);
            $table->unsignedSmallInteger('shots_blocked')->default(0);
            $table->unsignedSmallInteger('shots_missed')->default(0);
            $table->unsignedSmallInteger('shots_inside_pa')->default(0);
            $table->unsignedSmallInteger('shots_outside_pa')->default(0);
            $table->unsignedSmallInteger('offsides')->default(0);
            $table->unsignedSmallInteger('freekicks_taken')->default(0);
            $table->unsignedSmallInteger('corners_taken')->default(0);
            $table->unsignedSmallInteger('throw_ins')->default(0);
            $table->unsignedSmallInteger('take_ons_attempted')->default(0);
            $table->unsignedSmallInteger('take_ons_succeeded')->default(0);

            // --- Distribution ------------------------------------------------
            $table->unsignedSmallInteger('passes_total')->default(0);
            $table->unsignedSmallInteger('passes_succeeded')->default(0);
            $table->decimal('pass_accuracy_pct', 5, 2)->nullable();
            $table->unsignedSmallInteger('key_passes')->default(0);
            $table->unsignedSmallInteger('crosses_attempted')->default(0);
            $table->unsignedSmallInteger('crosses_succeeded')->default(0);
            $table->unsignedSmallInteger('controls_under_pressure')->default(0);

            // --- Defensive ---------------------------------------------------
            $table->unsignedSmallInteger('tackles_attempted')->default(0);
            $table->unsignedSmallInteger('tackles_succeeded')->default(0);
            $table->unsignedSmallInteger('aerial_duels_total')->default(0);
            $table->unsignedSmallInteger('aerial_duels_won')->default(0);
            $table->unsignedSmallInteger('ground_duels_total')->default(0);
            $table->unsignedSmallInteger('ground_duels_won')->default(0);
            $table->unsignedSmallInteger('interceptions')->default(0);
            $table->unsignedSmallInteger('clearances')->default(0);
            $table->unsignedSmallInteger('interventions')->default(0);
            $table->unsignedSmallInteger('recoveries')->default(0);
            $table->unsignedSmallInteger('blocks')->default(0);
            $table->unsignedSmallInteger('mistakes')->default(0);

            // --- Discipline --------------------------------------------------
            $table->unsignedSmallInteger('fouls_committed')->default(0);
            $table->unsignedSmallInteger('fouls_won')->default(0);
            $table->unsignedSmallInteger('yellow_cards')->default(0);
            $table->unsignedSmallInteger('red_cards')->default(0);

            // --- GK-only (NULL for outfield) --------------------------------
            $table->unsignedSmallInteger('goals_conceded')->nullable();
            $table->unsignedSmallInteger('catches')->nullable();
            $table->unsignedSmallInteger('parries')->nullable();
            $table->unsignedSmallInteger('goal_kicks_attempted')->nullable();
            $table->unsignedSmallInteger('goal_kicks_succeeded')->nullable();
            $table->unsignedSmallInteger('aerial_clearances_attempted')->nullable();
            $table->unsignedSmallInteger('aerial_clearances_succeeded')->nullable();

            // Pass area/direction/length breakdowns, average-position coords per
            // 15-min interval, any event-log slices for this player. Anything
            // not worth a column for SQL queries sits here.
            $table->{$jsonType}('raw_extracted')->nullable();

            // Set on apply for resolved Bahrain rows. Lets MatchPerformance jump
            // straight to its PlayerRecord (and via that, record_metrics).
            // nullOnDelete — purging the PlayerRecord shouldn't drop the match row.
            $table->foreignId('player_record_id')->nullable()
                ->constrained('player_records')->nullOnDelete();

            $table->timestampsTz();

            // All performances for a fixture in one shot, grouped by side
            $table->index(['match_report_id', 'team_side'], 'match_performances_fixture_idx');

            // A player's match history across the squad (joined with match_reports
            // for the date sort — kept narrow here so the index stays small)
            $table->index(['player_id', 'match_report_id'], 'match_performances_player_history_idx');

            // Anonymous-row auto-resolution: "find prior matches where Bahrain
            // jersey=5 was resolved to a player_id"
            $table->index(['team_side', 'jersey_number'], 'match_performances_jersey_lookup_idx');

            // Allow PlayerRecord → MatchPerformance reverse lookup without a scan
            $table->index('player_record_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_performances');
    }
};
