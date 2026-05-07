<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('player_id')
                ->constrained('players')->cascadeOnDelete();

            // Null-on-delete — alert survives the purge of its triggering record
            $table->foreignId('record_id')->nullable()
                ->constrained('player_records')->nullOnDelete();

            // info | warn | critical
            $table->string('severity', 10);

            // low_vit_d, iron_deficiency_risk, overtraining, abnormal_weight_change,
            // bf_delta_high, sodium_abnormal, dehydration_risk, etc.
            $table->string('kind', 60);

            $table->text('message');

            $table->timestampTz('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestampsTz();
            $table->softDeletesTz();

            // Dashboard query: unacknowledged critical + warn alerts per player
            $table->index(['player_id', 'severity', 'acknowledged_at']);
            $table->index(['severity', 'acknowledged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
