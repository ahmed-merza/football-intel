<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();

            // Source channel for the submission (e.g. manual_upload).
            $table->string('channel', 20);

            // Pre-set at upload time. Nullable to allow late-binding flows.
            $table->foreignId('player_id')->nullable()
                ->constrained('players')->cascadeOnDelete();

            // Null for non-interactive channels, non-null for manual_upload
            $table->foreignId('uploaded_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestampTz('received_at');

            // processing | classified | failed | needs_review
            $table->string('status', 30)->default('processing');

            $table->timestampTz('processed_at')->nullable();

            // Admin's free-text note at upload time (context, what they were looking for, etc.)
            $table->text('notes')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['player_id', 'received_at']);
            $table->index(['status', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};
