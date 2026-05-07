<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\PendingExtraction;
use App\Models\Player;
use App\Models\PlayerRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReapStalePendingExtractionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_expires_only_rows_past_their_expiry(): void
    {
        $player = Player::factory()->create();
        $record = PlayerRecord::factory()->bloodTest()->create([
            'player_id' => $player->id,
            'extracted' => [
                'awaiting_callback' => true,
                'callback_correlation_id' => 'abc',
            ],
        ]);

        $stale = PendingExtraction::create([
            'correlation_id' => (string) Str::uuid(),
            'record_id' => $record->id,
            'kind' => PendingExtraction::KIND_BLOOD_TEST,
            'status' => PendingExtraction::STATUS_PENDING,
            'expires_at' => Carbon::now()->subMinutes(2),
        ]);

        $fresh = PendingExtraction::create([
            'correlation_id' => (string) Str::uuid(),
            'record_id' => $record->id,
            'kind' => PendingExtraction::KIND_BLOOD_TEST,
            'status' => PendingExtraction::STATUS_PENDING,
            'expires_at' => Carbon::now()->addMinutes(10),
        ]);

        $this->artisan('ai:reap-pending')->assertExitCode(0);

        $this->assertSame(PendingExtraction::STATUS_EXPIRED, $stale->fresh()->status);
        $this->assertNotNull($stale->fresh()->processed_at);
        $this->assertSame(PendingExtraction::STATUS_PENDING, $fresh->fresh()->status);
    }

    public function test_command_flips_owning_record_back_to_failed(): void
    {
        $player = Player::factory()->create();
        $record = PlayerRecord::factory()->bloodTest()->create([
            'player_id' => $player->id,
            'extracted' => [
                'awaiting_callback' => true,
                'callback_correlation_id' => 'abc',
                'classifier' => ['category' => 'blood_test'],
            ],
        ]);

        PendingExtraction::create([
            'correlation_id' => (string) Str::uuid(),
            'record_id' => $record->id,
            'kind' => PendingExtraction::KIND_BLOOD_TEST,
            'status' => PendingExtraction::STATUS_PENDING,
            'expires_at' => Carbon::now()->subMinute(),
        ]);

        $this->artisan('ai:reap-pending')->assertExitCode(0);

        $extracted = $record->fresh()->extracted;
        $this->assertFalse($extracted['awaiting_callback'] ?? false);
        $this->assertTrue($extracted['extraction_failed']);
        $this->assertNotNull($extracted['extraction_failed_at']);
        $this->assertStringContainsString(
            'callback',
            (string) $extracted['extraction_failure_reason'],
        );
        // Classifier breadcrumb should still be there.
        $this->assertSame('blood_test', $extracted['classifier']['category']);
    }

    public function test_dry_run_does_not_write(): void
    {
        $player = Player::factory()->create();
        $record = PlayerRecord::factory()->bloodTest()->create([
            'player_id' => $player->id,
            'extracted' => ['awaiting_callback' => true],
        ]);
        $pending = PendingExtraction::create([
            'correlation_id' => (string) Str::uuid(),
            'record_id' => $record->id,
            'kind' => PendingExtraction::KIND_BLOOD_TEST,
            'status' => PendingExtraction::STATUS_PENDING,
            'expires_at' => Carbon::now()->subMinute(),
        ]);

        $this->artisan('ai:reap-pending', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(PendingExtraction::STATUS_PENDING, $pending->fresh()->status);
        $this->assertTrue($record->fresh()->extracted['awaiting_callback']);
    }
}
