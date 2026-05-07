<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Jobs\ExtractStructuredDataJob;
use App\Models\PlayerRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class RecordReExtractTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_dispatches_extract_job_for_the_record(): void
    {
        Bus::fake();
        $admin = User::factory()->create();
        $record = PlayerRecord::factory()->bloodTest()->create();

        $this->actingAs($admin)
            ->post("/records/{$record->id}/re-extract")
            ->assertRedirect();

        Bus::assertDispatched(
            ExtractStructuredDataJob::class,
            fn ($job) => $job->playerRecordId === $record->id,
        );
    }

    public function test_post_clears_a_stale_failure_flag_immediately(): void
    {
        Bus::fake();
        $admin = User::factory()->create();
        $record = PlayerRecord::factory()->bloodTest()->create([
            'extracted' => [
                'labs' => [],
                'extraction_failed' => true,
                'extraction_failed_at' => '2026-04-23T19:00:00+00:00',
                'extraction_failure_reason' => 'Timeout',
            ],
        ]);

        $this->actingAs($admin)
            ->post("/records/{$record->id}/re-extract")
            ->assertRedirect();

        $extracted = $record->fresh()->extracted;
        $this->assertArrayNotHasKey('extraction_failed', $extracted);
        $this->assertArrayNotHasKey('extraction_failed_at', $extracted);
        $this->assertArrayNotHasKey('extraction_failure_reason', $extracted);
    }

    public function test_re_extract_requires_authentication(): void
    {
        $record = PlayerRecord::factory()->bloodTest()->create();

        $this->post("/records/{$record->id}/re-extract")
            ->assertRedirect('/login');
    }

    public function test_mark_reviewed_flips_state_with_reviewer_and_timestamp(): void
    {
        $admin = User::factory()->create();
        $record = PlayerRecord::factory()->bloodTest()->create([
            'reviewed' => false,
        ]);

        $this->actingAs($admin)
            ->post("/records/{$record->id}/review")
            ->assertRedirect();

        $record->refresh();
        $this->assertTrue($record->reviewed);
        $this->assertNotNull($record->reviewed_at);
        $this->assertSame($admin->id, $record->reviewed_by);
    }

    public function test_mark_reviewed_is_idempotent(): void
    {
        $admin = User::factory()->create();
        $record = PlayerRecord::factory()->bloodTest()->reviewed()->create([
            'reviewed_by' => $admin->id,
        ]);
        // Record updated_at via raw DB read so we side-step any cast/TZ
        // round-trip — if the controller no-ops, updated_at on disk is
        // byte-for-byte identical.
        $originalUpdatedAt = (string) DB::table('player_records')
            ->where('id', $record->id)
            ->value('updated_at');

        $this->actingAs($admin)
            ->post("/records/{$record->id}/review")
            ->assertRedirect();

        $this->assertSame(
            $originalUpdatedAt,
            (string) DB::table('player_records')
                ->where('id', $record->id)
                ->value('updated_at'),
            'Already-reviewed record should not be touched on a re-review request.',
        );
    }

    public function test_mark_reviewed_requires_authentication(): void
    {
        $record = PlayerRecord::factory()->bloodTest()->create();

        $this->post("/records/{$record->id}/review")
            ->assertRedirect('/login');
    }

    public function test_undo_review_clears_reviewed_state_reviewer_and_timestamp(): void
    {
        $admin = User::factory()->create();
        $record = PlayerRecord::factory()->bloodTest()->reviewed()->create([
            'reviewed_by' => $admin->id,
        ]);
        $this->assertTrue($record->reviewed);

        $this->actingAs($admin)
            ->delete("/records/{$record->id}/review")
            ->assertRedirect();

        $record->refresh();
        $this->assertFalse($record->reviewed);
        $this->assertNull($record->reviewed_at);
        $this->assertNull($record->reviewed_by);
    }

    public function test_undo_review_is_idempotent_on_already_unreviewed_records(): void
    {
        $admin = User::factory()->create();
        $record = PlayerRecord::factory()->bloodTest()->create([
            'reviewed' => false,
        ]);
        $originalUpdatedAt = (string) DB::table('player_records')
            ->where('id', $record->id)
            ->value('updated_at');

        $this->actingAs($admin)
            ->delete("/records/{$record->id}/review")
            ->assertRedirect();

        $this->assertSame(
            $originalUpdatedAt,
            (string) DB::table('player_records')
                ->where('id', $record->id)
                ->value('updated_at'),
        );
    }

    public function test_undo_review_requires_authentication(): void
    {
        $record = PlayerRecord::factory()->bloodTest()->reviewed()->create();

        $this->delete("/records/{$record->id}/review")
            ->assertRedirect('/login');
    }

    public function test_failed_hook_marks_record_with_extraction_failed_flag(): void
    {
        $record = PlayerRecord::factory()->bloodTest()->create([
            'extracted' => ['labs' => [['name' => 'Hb', 'value' => 14]]],
        ]);

        $job = new ExtractStructuredDataJob($record->id);
        $job->failed(new RuntimeException('Operation timed out after 300002 milliseconds'));

        $extracted = $record->fresh()->extracted;
        $this->assertTrue($extracted['extraction_failed']);
        $this->assertNotNull($extracted['extraction_failed_at']);
        $this->assertStringContainsString('timed out', $extracted['extraction_failure_reason']);
        // Existing labs should NOT be wiped on failure — admin can still
        // see whatever previous successful run produced.
        $this->assertCount(1, $extracted['labs']);
    }
}
