<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();

            // null for team-wide / aggregate reports
            $table->foreignId('player_id')->nullable()
                ->constrained('players')->cascadeOnDelete();

            // player | team
            $table->string('type', 20);

            $table->string('title', 255);

            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();

            $table->string('storage_disk', 30)->default('local');
            $table->string('storage_path', 512);

            $table->foreignId('generated_by')->nullable()
                ->constrained('users')->nullOnDelete();

            // Which sections were included, narrative model, KB doc ids cited, etc.
            $table->jsonb('meta')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['player_id', 'created_at']);
            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
