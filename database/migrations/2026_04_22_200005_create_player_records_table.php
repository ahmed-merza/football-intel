<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_records', function (Blueprint $table) {
            $table->id();

            $table->foreignId('player_id')
                ->constrained('players')->cascadeOnDelete();

            // restrictOnDelete — a category with live records should never vanish
            $table->foreignId('category_id')
                ->constrained('record_categories')->restrictOnDelete();

            // Sample date, test date, session date — as printed on the source document
            $table->date('record_date');

            // Null-on-delete — a record can survive the purge of its original submission
            $table->foreignId('submission_id')->nullable()
                ->constrained('submissions')->nullOnDelete();

            $table->foreignId('primary_attachment_id')->nullable()
                ->constrained('attachments')->nullOnDelete();

            // Category-specific typed payload. NOT encrypted at the model layer
            // because the record_metrics fan-out + dashboard queries need to
            // read individual fields. Protection at rest is delegated to
            // disk/backup-level encryption (Postgres TDE / encrypted disk).
            $table->jsonb('extracted');

            // Nutritionist Assistant output (blood + body combined analysis).
            // Same unencrypted-but-disk-protected rationale as `extracted`.
            $table->jsonb('analysis')->nullable();
            $table->timestampTz('analysis_generated_at')->nullable();

            // LLM narrative / one-paragraph summary of the record itself
            $table->text('summary_text')->nullable();

            $table->string('source_lab', 100)->nullable();

            $table->boolean('reviewed')->default(false);
            $table->timestampTz('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->text('admin_notes')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            // Primary timeline query: all records for a player, by category, newest first
            $table->index(['player_id', 'category_id', 'record_date'], 'player_records_timeline_idx');

            // Secondary: recent records across the whole squad (dashboard feed)
            $table->index(['record_date', 'category_id']);

            $table->index('submission_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_records');
    }
};
