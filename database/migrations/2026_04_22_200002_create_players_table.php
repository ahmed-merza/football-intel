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
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->string('full_name', 255);
            $table->string('name_ar', 255)->nullable();
            $table->string('club', 255)->nullable();
            $table->string('position', 50)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('nationality', 100)->nullable();
            $table->unsignedSmallInteger('height_cm')->nullable();
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->string('preferred_foot', 10)->nullable();
            $table->string('player_code', 50)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('photo_path', 512)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('status');
            $table->index('full_name');
        });

        // Partial unique indexes — soft-deleted rows can keep their values without
        // blocking a replacement. Raw SQL because Laravel's Schema DSL doesn't
        // support `WHERE` clauses on indexes.
        DB::statement(
            'CREATE UNIQUE INDEX players_player_code_unique_active ON players (player_code) WHERE deleted_at IS NULL AND player_code IS NOT NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX players_phone_unique_active ON players (phone) WHERE deleted_at IS NULL AND phone IS NOT NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX players_email_unique_active ON players (email) WHERE deleted_at IS NULL AND email IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('players');
    }
};
