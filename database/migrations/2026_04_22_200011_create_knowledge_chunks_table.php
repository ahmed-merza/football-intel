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
        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('document_id')
                ->constrained('knowledge_documents')->cascadeOnDelete();

            $table->unsignedSmallInteger('chunk_index');

            $table->text('content');

            // 1024-dim to match Voyage voyage-3-large. Swap via a new migration
            // + re-embed job if we switch providers.
            $table->vector('embedding', 1024);

            // section title, page number, token span, original doc position
            $table->jsonb('metadata')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['document_id', 'chunk_index']);
        });

        // HNSW index for cosine similarity — fast approximate nearest neighbours.
        // Native $table->vectorIndex() defaults don't let us pick the ops class,
        // so we go raw SQL. Skipped on non-pgsql (the vector column itself also
        // needs pgsql; the whole table is effectively pgsql-only).
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX knowledge_chunks_embedding_hnsw ON knowledge_chunks USING hnsw (embedding vector_cosine_ops)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunks');
    }
};
