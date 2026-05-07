<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per outbound n8n call. Acts as the durable correlation
     * point between the request we sent and either:
     *   (a) the sync HTTP response — we close the row inline.
     *   (b) the asynchronous callback POST that arrives later when
     *       nginx 504s on the sync attempt — webhook handler closes
     *       the row by correlation_id.
     *
     * Idempotency: webhook checks status before applying; a callback
     * arriving for an already-completed correlation is a no-op.
     */
    public function up(): void
    {
        Schema::create('pending_extractions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('correlation_id')->unique();

            // Owning record (nullable — we may use this for non-record
            // calls later, e.g. the classifier). When the record is
            // hard-deleted the pending row goes with it.
            $table->foreignId('record_id')
                ->nullable()
                ->constrained('player_records')
                ->cascadeOnDelete();

            // What kind of agent the call was for, so the webhook
            // applier can dispatch correctly. Free-form for forward
            // compat — current values: blood_test, inbody, nutrition_plan.
            $table->string('kind', 40);

            $table->string('status', 20)->default('pending')->index();

            // Original request envelope (system_prompt + user_prompt).
            // Stored so a callback handler can re-derive whatever it
            // needs without re-hitting the source attachment, and for
            // debugging when something goes wrong.
            $table->jsonb('request')->nullable();

            // n8n response payload as we received it (sync or callback).
            // Same shape either way.
            $table->jsonb('result')->nullable();

            // Humanised error if the call failed (sync 5xx other than
            // 504, JSON parse error, schema mismatch, etc.).
            $table->text('error')->nullable();

            $table->timestamps();
            $table->timestamp('processed_at')->nullable();

            // Reaper anchor — anything older than this in `pending` gets
            // marked expired so the UI stops showing "extraction pending"
            // forever when a callback never arrives.
            $table->timestamp('expires_at');

            $table->index(['record_id', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_extractions');
    }
};
