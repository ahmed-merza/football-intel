<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Match-report ingestion target. One row per uploaded PDF (typically
 * 50 pages of Wyscout-style team + per-player stats covering both sides
 * of a fixture).
 *
 * Lifecycle (see status column):
 *   pending           — row created, extraction not yet dispatched
 *   extracting        — sync n8n call in flight
 *   awaiting_callback — sync timed out; n8n still working, will POST later
 *   extracted         — extractor returned, awaiting admin's player-resolution step
 *   applied           — admin resolved players + per-player rows committed
 *   failed            — non-timeout error from the extractor (retry button surfaces this)
 *
 * `raw_extracted` holds the full extractor payload (match meta + team
 * totals + formations + average-position intervals + every per-player
 * row). It is the source of truth for the admin's resolution preview;
 * once `applied`, the canonical per-player data lives in `match_performances`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        $isPg = $driver === 'pgsql';
        $jsonType = $isPg ? 'jsonb' : 'json';

        Schema::create('match_reports', function (Blueprint $table) use ($jsonType) {
            $table->id();

            // Extractor format key. Default fits the AGCFF/Wyscout layout we ship
            // with; future providers (different layout, different shape) get their
            // own slug + extractor without a migration.
            $table->string('source', 50)->default('agcff_match_report');

            // Match meta — populated by the extractor; nullable on insert
            // because the row is created at upload time (before the PDF has
            // been parsed). MatchReportApplier::applyExtraction() fills them in.
            $table->string('competition', 255)->nullable();
            $table->string('stage', 100)->nullable();
            $table->date('match_date')->nullable();
            $table->time('kickoff_time', 0)->nullable();
            $table->string('venue', 255)->nullable();

            // Teams + score as printed; bahrain_side derived after extraction.
            $table->string('home_team_name', 100)->nullable();
            $table->string('away_team_name', 100)->nullable();
            $table->unsignedSmallInteger('home_score')->nullable();
            $table->unsignedSmallInteger('away_score')->nullable();

            // 'home' | 'away' | null when neither team is Bahrain (shouldn't happen
            // in V1 but the column allows it; we just wouldn't write player_records).
            // Stored as string (not enum) for cross-DB simplicity.
            $table->string('bahrain_side', 10)->nullable();
            $table->string('opponent_name', 100)->nullable();

            // Provenance — the PDF that produced this row + the actor who uploaded it.
            // submission_id matches the existing intake convention; attachment_id is
            // the specific PDF inside that submission.
            $table->foreignId('submission_id')->nullable()
                ->constrained('submissions')->nullOnDelete();
            $table->foreignId('attachment_id')->nullable()
                ->constrained('attachments')->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()
                ->constrained('users')->nullOnDelete();

            // Lifecycle. 'pending' on create; 'extracting'/'awaiting_callback'
            // during the LLM round-trip; 'extracted' when the payload is ready
            // for admin resolution; 'applied' when the admin has saved the
            // resolution; 'failed' on a genuine extractor error.
            $table->string('status', 30)->default('pending');
            $table->text('extraction_error')->nullable();

            // Full extractor output: per-player rows + team totals + formations
            // + average-position intervals + event log. Survives past 'applied'
            // for audit / re-apply if extraction logic changes.
            $table->{$jsonType}('raw_extracted')->nullable();
            $table->timestampTz('extracted_at')->nullable();
            $table->timestampTz('applied_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('match_date');
            $table->index(['competition', 'match_date']);
            $table->index(['bahrain_side', 'match_date']);
            $table->index(['status', 'updated_at']);
        });

        // Dedupe at the attachment level — same PDF can't produce two
        // match_reports rows. The SubmissionController already short-circuits
        // re-uploads via attachment.sha256, so this is belt-and-braces; it
        // also lets a re-extract on the same attachment update in place
        // (the applier finds the row by attachment_id when needed).
        // Soft-deleted rows excluded so the admin can re-import after delete.
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX match_reports_attachment_unique_active
                ON match_reports (attachment_id)
                WHERE deleted_at IS NULL AND attachment_id IS NOT NULL'
            );
        } else {
            // MySQL: plain unique. NULL attachment_id rows allowed since
            // MySQL treats NULLs as distinct in unique indexes.
            Schema::table('match_reports', function (Blueprint $table) {
                $table->unique('attachment_id', 'match_reports_attachment_unique_active');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('match_reports');
    }
};
