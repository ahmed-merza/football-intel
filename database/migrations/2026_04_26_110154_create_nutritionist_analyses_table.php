<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores Nutritionist Assistant runs — one row per agent invocation.
 * Lifecycle: pending (job dispatched) → completed (payload populated)
 * OR failed (error message captured). History is preserved (soft
 * deletes) so the admin can compare advice over time + future runs
 * can pick up context from past flags.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nutritionist_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')
                ->constrained('players')
                ->cascadeOnDelete();
            $table->foreignId('generated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('status', 20)->default('pending');
            $table->string('model_used', 100)->nullable();
            $table->text('error')->nullable();

            // Headline the list view shows without parsing the full
            // payload — denormalised from payload.summary on completion.
            $table->text('summary_text')->nullable();

            // Full structured output from the agent (see
            // App\Ai\Agents\NutritionistAssistant::schema). Kept as
            // jsonb so dashboard queries can read individual fields
            // without deserialising the whole blob.
            $table->jsonb('payload')->nullable();

            // Source records that fed this analysis — lets the admin
            // jump back to the underlying blood / InBody, and lets
            // future re-runs know what changed.
            $table->foreignId('source_blood_record_id')
                ->nullable()
                ->constrained('player_records')
                ->nullOnDelete();
            $table->foreignId('source_inbody_record_id')
                ->nullable()
                ->constrained('player_records')
                ->nullOnDelete();

            $table->timestampTz('generated_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['player_id', 'generated_at'], 'nutritionist_analyses_player_latest');
            $table->index(['player_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nutritionist_analyses');
    }
};
