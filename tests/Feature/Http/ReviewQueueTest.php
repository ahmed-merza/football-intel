<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Jobs\ExtractStructuredDataJob;
use App\Models\PlayerRecord;
use App\Models\RecordCategory;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ReviewQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_only_lists_unreviewed_records_belonging_to_needs_review_submissions(): void
    {
        $admin = User::factory()->create();

        $needsReview = Submission::factory()->needsReview()->create();
        $record = PlayerRecord::factory()->bloodTest()->create([
            'submission_id' => $needsReview->id,
            'reviewed' => false,
        ]);

        // Already reviewed — excluded
        PlayerRecord::factory()->bloodTest()->reviewed()->create([
            'submission_id' => $needsReview->id,
        ]);

        // Submission is classified — excluded regardless of reviewed flag
        $classified = Submission::factory()->create([
            'status' => Submission::STATUS_CLASSIFIED,
        ]);
        PlayerRecord::factory()->bloodTest()->create([
            'submission_id' => $classified->id,
            'reviewed' => false,
        ]);

        $response = $this->actingAs($admin)->get('/review');
        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $this->assertSame(1, $props['count']);
        $this->assertSame($record->id, $props['records'][0]['id']);
    }

    public function test_confirm_with_no_category_change_just_flips_reviewed_true(): void
    {
        Bus::fake();
        $admin = User::factory()->create();

        $submission = Submission::factory()->needsReview()->create();
        $record = PlayerRecord::factory()->bloodTest()->create([
            'submission_id' => $submission->id,
            'reviewed' => false,
        ]);

        $this->actingAs($admin)
            ->post("/review/{$record->id}/confirm")
            ->assertRedirect();

        $this->assertTrue($record->refresh()->reviewed);
        $this->assertNotNull($record->reviewed_at);
        $this->assertSame($admin->id, $record->reviewed_by);

        // Only one record on this submission, and it's now reviewed → promoted
        $this->assertSame(Submission::STATUS_CLASSIFIED, $submission->refresh()->status);

        // No category change → no re-extraction dispatched
        Bus::assertNothingDispatched();
    }

    public function test_confirm_with_category_override_re_dispatches_extraction(): void
    {
        Bus::fake();
        $admin = User::factory()->create();

        $submission = Submission::factory()->needsReview()->create();

        // Classifier had picked inbody, admin disagrees
        $record = PlayerRecord::factory()->inbody()->create([
            'submission_id' => $submission->id,
            'reviewed' => false,
        ]);

        $this->actingAs($admin)
            ->post(
                "/review/{$record->id}/confirm",
                ['category_slug' => RecordCategory::BLOOD_TEST],
            )
            ->assertRedirect();

        $record->refresh();
        $this->assertTrue($record->reviewed);
        $this->assertSame(
            RecordCategory::where('slug', RecordCategory::BLOOD_TEST)->value('id'),
            $record->category_id,
        );

        Bus::assertDispatched(ExtractStructuredDataJob::class, function ($job) use ($record): bool {
            return $job->playerRecordId === $record->id;
        });
    }

    public function test_submission_stays_in_needs_review_until_every_record_is_reviewed(): void
    {
        Bus::fake();
        $admin = User::factory()->create();

        $submission = Submission::factory()->needsReview()->create();
        $r1 = PlayerRecord::factory()->bloodTest()->create([
            'submission_id' => $submission->id,
            'reviewed' => false,
        ]);
        PlayerRecord::factory()->bloodTest()->create([
            'submission_id' => $submission->id,
            'reviewed' => false,
        ]);

        // Confirm just the first one
        $this->actingAs($admin)
            ->post("/review/{$r1->id}/confirm")
            ->assertRedirect();

        $this->assertSame(
            Submission::STATUS_NEEDS_REVIEW,
            $submission->refresh()->status,
            'Submission stays in needs_review until all its records are done.',
        );
    }

    public function test_confirm_rejects_unknown_category_slugs(): void
    {
        $admin = User::factory()->create();
        $record = PlayerRecord::factory()->bloodTest()->create([
            'submission_id' => Submission::factory()->needsReview()->create()->id,
            'reviewed' => false,
        ]);

        $this->actingAs($admin)
            ->post(
                "/review/{$record->id}/confirm",
                ['category_slug' => 'bogus_slug'],
            )
            ->assertSessionHasErrors('category_slug');

        $this->assertFalse($record->fresh()->reviewed);
    }

    public function test_review_requires_authentication(): void
    {
        $this->get('/review')->assertRedirect('/login');
    }
}
