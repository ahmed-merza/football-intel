<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // No-op on non-pgsql connections (e.g. sqlite in unit tests that don't
        // touch pgvector). Feature tests run against Postgres in CI so the
        // extensions get installed there.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // pgvector — vector columns + HNSW/IVFFlat indexes for the knowledge base
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        // pg_trgm — fuzzy player-name matching
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
    }

    public function down(): void
    {
        // Intentional no-op: extensions are shared across the whole database.
        // Dropping them would break any other schema on the same DB. A real
        // rollback (e.g. for a full env teardown) should drop the database.
    }
};
