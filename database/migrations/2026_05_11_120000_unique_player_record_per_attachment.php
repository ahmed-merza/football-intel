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
        // Strip any existing duplicates first — the unique index would
        // fail to build otherwise. Keep the lowest id per attachment
        // (the original successful classification). The losing rows are
        // both soft-deleted (so the app stops surfacing them, but admins
        // can audit them via withTrashed()) AND have primary_attachment_id
        // nulled out — soft-deleted rows still occupy unique-index slots,
        // so dropping the FK is what actually lets the constraint build.
        $duplicates = DB::table('player_records')
            ->select('primary_attachment_id', DB::raw('MIN(id) as keep_id'))
            ->whereNotNull('primary_attachment_id')
            ->whereNull('deleted_at')
            ->groupBy('primary_attachment_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $dup) {
            DB::table('player_records')
                ->where('primary_attachment_id', $dup->primary_attachment_id)
                ->where('id', '!=', $dup->keep_id)
                ->whereNull('deleted_at')
                ->update([
                    'deleted_at' => now(),
                    'primary_attachment_id' => null,
                ]);
        }

        Schema::table('player_records', function (Blueprint $table) {
            $table->unique('primary_attachment_id', 'player_records_primary_attachment_unique');
        });
    }

    public function down(): void
    {
        Schema::table('player_records', function (Blueprint $table) {
            $table->dropUnique('player_records_primary_attachment_unique');
        });
    }
};
