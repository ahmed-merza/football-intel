<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 (A) — Goalkeeper save events.
 *
 * The "Goalkeeper" section of an AGCFF report (pages 44-47) prints a
 * per-keeper save log: a textual table of every save event with its
 * minute, the opponent who took the shot, and the body part used. The
 * Goalkeeper Events extractor lifts that table into structured rows.
 *
 * Player linkage is the same pattern as match_shot_events: store
 * (jersey_number, team_side) on the row; the JOIN against
 * match_performances gives you the keeper's player_id (Bahrain side)
 * after the admin's resolution UI step.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isPg = DB::connection()->getDriverName() === 'pgsql';
        $jsonType = $isPg ? 'jsonb' : 'json';

        Schema::create('match_goalkeeper_events', function (Blueprint $table) use ($jsonType): void {
            $table->id();

            $table->foreignId('match_report_id')
                ->constrained('match_reports')->cascadeOnDelete();

            // 1-based order in the saves table for this keeper.
            $table->unsignedSmallInteger('sequence');

            $table->unsignedSmallInteger('minute');

            // 'bahrain' | 'opponent' — refers to the KEEPER's team.
            $table->string('team_side', 10);

            // Keeper identity.
            $table->unsignedTinyInteger('jersey_number');
            $table->string('reported_name', 255)->nullable();

            // Save outcome — the report categorises by:
            //   catch    — keeper held the ball
            //   parry    — punched / palmed away
            //   conceded — ball went in (goals_conceded count)
            //   own_goal — opponent's own goal credited
            $table->string('outcome', 20);

            // Who took the shot (opponent identity for context, NOT the keeper).
            $table->string('opponent_reported_name', 255)->nullable();
            $table->unsignedTinyInteger('opponent_jersey_number')->nullable();

            // Body part of the shot taker. "Right Foot" / "Left Foot" / "Header".
            $table->string('body_part', 30)->nullable();

            $table->boolean('is_penalty')->default(false);

            $table->{$jsonType}('raw_extracted')->nullable();

            $table->timestampsTz();

            $table->index(['match_report_id', 'sequence'], 'gk_events_timeline_idx');
            $table->index(['match_report_id', 'team_side', 'jersey_number'], 'gk_events_jersey_lookup_idx');
            $table->index(['outcome', 'match_report_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_goalkeeper_events');
    }
};
