<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('submission_id')
                ->constrained('submissions')->cascadeOnDelete();

            $table->string('original_filename', 512);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');

            $table->string('storage_disk', 30)->default('local');
            $table->string('storage_path', 512);

            // SHA-256 hex (64 chars) — dedupes re-uploads of the same file
            $table->char('sha256', 64);

            $table->unsignedSmallInteger('page_count')->nullable();
            $table->boolean('is_text_pdf')->nullable();

            // Encrypted at the model layer — medical content in raw extracted text
            $table->text('extracted_text')->nullable();

            $table->boolean('ocr_used')->default(false);
            $table->decimal('ocr_confidence', 3, 2)->nullable();

            $table->string('thumbnail_path', 512)->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('submission_id');
        });

        // Partial unique on sha256 — dedupe only applies to live attachments;
        // soft-deleted rows keep the hash so we can still audit / recover.
        // MySQL/MariaDB don't support partial indexes; fall back to plain UNIQUE
        // there (re-uploading a soft-deleted attachment will need a hard purge
        // first, acceptable for dev).
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX attachments_sha256_unique_active ON attachments (sha256) WHERE deleted_at IS NULL'
            );
        } else {
            Schema::table('attachments', function (Blueprint $table) {
                $table->unique('sha256', 'attachments_sha256_unique_active');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
