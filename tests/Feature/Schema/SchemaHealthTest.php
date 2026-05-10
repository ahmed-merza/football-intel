<?php

declare(strict_types=1);

use App\Models\RecordCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates every domain table', function (): void {
    $tables = [
        'users', 'audits', 'record_categories', 'players', 'submissions',
        'attachments', 'player_records', 'record_metrics', 'alerts',
        'player_notes', 'classification_overrides', 'knowledge_documents',
        'knowledge_chunks', 'reports',
    ];

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("table {$table} missing");
    }
});

it('registers pgvector and pg_trgm extensions', function (): void {
    $extensions = DB::table('pg_extension')->pluck('extname')->all();

    expect($extensions)
        ->toContain('vector')
        ->toContain('pg_trgm');
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'pgsql',
    'pg_extension introspection is Postgres-only — pgvector/pg_trgm are the production stack.'
);

it('seeds the 8 canonical record categories inline with the migration', function (): void {
    $slugs = RecordCategory::orderBy('sort_order')->pluck('slug')->all();

    expect($slugs)->toEqual([
        RecordCategory::BLOOD_TEST,
        RecordCategory::INBODY,
        RecordCategory::GPS_WEARABLE,
        RecordCategory::NUTRITION_PLAN,
        RecordCategory::HYDRATION_SUPPLEMENT_PLAN,
        RecordCategory::COACH_FEEDBACK,
        RecordCategory::MATCH_ACTIVITY,
        RecordCategory::OTHER,
    ]);
});

it('enforces partial unique indexes on players so soft-deleted rows free up values', function (): void {
    // Raw Postgres introspection — the three partial unique indexes live outside
    // Laravel's Blueprint because the DSL doesn't support WHERE clauses.
    $partialIndexes = DB::select(<<<'SQL'
        SELECT indexname
        FROM pg_indexes
        WHERE tablename = 'players'
          AND indexdef ILIKE '%WHERE%deleted_at IS NULL%'
        ORDER BY indexname
    SQL);

    $names = array_map(fn (object $row): string => $row->indexname, $partialIndexes);

    expect($names)->toContain(
        'players_player_code_unique_active',
        'players_phone_unique_active',
        'players_email_unique_active',
    );
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'pgsql',
    'Partial indexes are Postgres-only; on MySQL the migrations fall back to plain UNIQUE.'
);
