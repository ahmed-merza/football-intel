<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_notes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('player_id')
                ->constrained('players')->cascadeOnDelete();

            // Notes can be attached to the whole player (record_id = null) or
            // to a specific record in the timeline
            $table->foreignId('record_id')->nullable()
                ->constrained('player_records')->cascadeOnDelete();

            $table->text('body');

            $table->foreignId('created_by')
                ->constrained('users')->cascadeOnDelete();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['player_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_notes');
    }
};
