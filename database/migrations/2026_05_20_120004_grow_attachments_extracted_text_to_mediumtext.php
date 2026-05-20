<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MySQL `TEXT` caps at 64 KB. The existing medical PDFs (blood tests +
 * InBody, 1–5 pages) fit comfortably, but match-report PDFs are 50+ pages —
 * once the raw text picks up Laravel's encryption envelope + base64 it
 * blows past 64 KB and the UPDATE 1406s.
 *
 * Bumping to MEDIUMTEXT (16 MB) gives effectively unlimited headroom for
 * any single document we'd realistically extract. No-op on Postgres
 * because `TEXT` is already unlimited there.
 *
 * Safe to run while data exists — MySQL ALTER TABLE … MODIFY widens the
 * column in place without truncating existing rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('attachments', function (Blueprint $table): void {
            $table->mediumText('extracted_text')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('attachments', function (Blueprint $table): void {
            $table->text('extracted_text')->nullable()->change();
        });
    }
};
