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
        $jsonType = DB::connection()->getDriverName() === 'pgsql' ? 'jsonb' : 'json';

        Schema::create('knowledge_documents', function (Blueprint $table) use ($jsonType) {
            $table->id();

            $table->string('title', 255);

            // nutrition_plan | supplement_guide | protocol | note
            $table->string('source_type', 40);

            $table->string('storage_disk', 30)->default('local');
            $table->string('storage_path', 512);

            // en | ar | mixed
            $table->string('language', 10)->default('en');

            // author, date, tags, etc.
            $table->{$jsonType}('metadata')->nullable();

            $table->foreignId('uploaded_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['source_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_documents');
    }
};
