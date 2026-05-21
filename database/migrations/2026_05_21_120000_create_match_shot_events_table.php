<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — Shot Events
 *
 * One row per shot taken in the match. Captures the minute, taker (by
 * jersey + reported name + team side), body part, outcome, and the
 * buildup chain (the sequence of players who touched the ball before
 * the shot, with their tagged actions). Population pipeline lives in
 * the parallel job:
 *
 *   ExtractMatchShotEventsJob → MatchShotEventsTextPreprocessor →
 *   MatchShotEventsExtractor (agent) → MatchShotEventsApplier
 *
 * Player linkage is deferred — these rows store (jersey_number,
 * team_side) only. The match_performances table (Phase 1) already
 * carries the resolved player_id once the admin clicks Apply, so a
 * JOIN on (match_report_id, team_side, jersey_number) gives every
 * shot event its taker. No backfill needed when admin resolves.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isPg = DB::connection()->getDriverName() === 'pgsql';
        $jsonType = $isPg ? 'jsonb' : 'json';

        Schema::create('match_shot_events', function (Blueprint $table) use ($jsonType): void {
            $table->id();

            $table->foreignId('match_report_id')
                ->constrained('match_reports')->cascadeOnDelete();

            // 1-based order in which the shot appears in the report.
            // Lets us reconstruct the match timeline without relying on
            // (minute, id) tuples — useful when the report itself is the
            // canonical source for "which shot was second".
            $table->unsignedSmallInteger('sequence');

            // Minute the shot was taken (1-90 plus added time). Stored
            // as smallint so 90'+6' lands as 96 without weirdness.
            $table->unsignedSmallInteger('minute');
            $table->unsignedSmallInteger('seconds')->nullable();

            // 'bahrain' | 'opponent' — same convention as match_performances
            // so the JOIN to resolve player_id works seamlessly.
            $table->string('team_side', 10);

            $table->unsignedTinyInteger('jersey_number');
            $table->string('reported_name', 255)->nullable();

            // Verbatim from the report — "Right Foot", "Left Foot", "Header",
            // "Other Body Part" — kept as VARCHAR not enum because the report
            // occasionally surfaces edge cases (chest, shin) we don't want to
            // pre-enumerate.
            $table->string('body_part', 30)->nullable();

            // Outcome bucket. The report visually distinguishes:
            //   goal       — entered the net
            //   on_target  — saved by GK / would have scored
            //   blocked    — blocked by defender (not GK)
            //   missed     — off-target
            //   other      — rush-outs, own goals (kept separate via flag)
            $table->string('outcome', 20);
            $table->boolean('is_penalty')->default(false);
            $table->boolean('is_own_goal')->default(false);

            // Sequence of players who handled the ball leading up to the
            // shot, each with their action tags (e.g. "Buildup Start",
            // "Successful Take-Ons", "Passes Succeeded"). One JSON blob
            // per shot — query needs are aggregate (count buildups,
            // count assists from a player), not per-action atomic, so
            // not worth a separate table yet.
            $table->{$jsonType}('buildup_chain')->nullable();

            // Full per-shot extractor slice — keeps any fields we didn't
            // promote to columns available for future queries / debugging.
            $table->{$jsonType}('raw_extracted')->nullable();

            $table->timestampsTz();

            // Time-ordered timeline for one match
            $table->index(['match_report_id', 'sequence'], 'shot_events_timeline_idx');

            // Player-history join target — equivalent to the
            // match_performances jersey-lookup index
            $table->index(['match_report_id', 'team_side', 'jersey_number'], 'shot_events_jersey_lookup_idx');

            // "All goals" filter
            $table->index(['outcome', 'match_report_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_shot_events');
    }
};
