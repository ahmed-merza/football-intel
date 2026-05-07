<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable audit trail for admin corrections to AI classification or
 * extraction. No soft deletes — we never hide audit rows. Hard-deleted only
 * when the parent submission is purged for GDPR (and that event is itself
 * logged in audit_logs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classification_overrides', function (Blueprint $table) {
            $table->id();

            $table->foreignId('submission_id')
                ->constrained('submissions')->cascadeOnDelete();

            $table->foreignId('attachment_id')->nullable()
                ->constrained('attachments')->cascadeOnDelete();

            // e.g. 'category', 'metric:hb_g_dl', 'extracted.patient_name'
            $table->string('field', 60);

            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();

            $table->foreignId('corrected_by')
                ->constrained('users')->restrictOnDelete();

            $table->timestampTz('corrected_at');

            // No updated_at — rows are immutable. Only created_at for completeness.
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['submission_id', 'corrected_at']);
            $table->index(['attachment_id', 'corrected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classification_overrides');
    }
};
