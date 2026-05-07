<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Attachment;
use App\Models\Player;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Classic PHPUnit-style class rather than Pest `it()` closures because
 * the HTTP helpers ($this->actingAs, $this->post) aren't resolvable by
 * Larastan inside Pest closures without the (unreleased) phpstan plugin.
 * The coverage is the same.
 */
class SubmissionUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        // These tests are about the upload / persistence layer only —
        // the ingestion chain (extract → classify) has its own suite
        // (IngestionChainTest). Faking the bus keeps these focused
        // and avoids the chain mutating submission status mid-test.
        Bus::fake();
    }

    public function test_it_persists_a_submission_with_one_attachment(): void
    {
        $admin = User::factory()->create();
        $player = Player::factory()->create();

        $response = $this->actingAs($admin)->post(
            "/players/{$player->id}/submissions",
            [
                'files' => [
                    UploadedFile::fake()->createWithContent(
                        'blood_test.pdf',
                        '%PDF-1.4 fake bytes for test A',
                    ),
                ],
                'category_hint' => 'blood_test',
                'notes' => 'Post-friendly match bloods.',
            ],
        );

        $response->assertRedirect();

        $submission = Submission::latest('id')->first();
        $this->assertNotNull($submission);
        $this->assertSame($player->id, $submission->player_id);
        $this->assertSame(Submission::CHANNEL_MANUAL_UPLOAD, $submission->channel);
        $this->assertSame(Submission::STATUS_PROCESSING, $submission->status);
        $this->assertStringContainsString('hint:blood_test', $submission->notes ?? '');
        $this->assertStringContainsString('Post-friendly match bloods.', $submission->notes ?? '');

        $attachment = Attachment::where('submission_id', $submission->id)->firstOrFail();
        $this->assertSame('blood_test.pdf', $attachment->original_filename);
        $this->assertSame('application/pdf', $attachment->mime_type);
        $this->assertGreaterThan(0, $attachment->size_bytes);
        $this->assertSame(64, strlen($attachment->sha256));
        $this->assertSame('local', $attachment->storage_disk);
        $this->assertStringStartsWith("attachments/{$player->id}/", $attachment->storage_path);
        $this->assertNull($attachment->extracted_text);

        Storage::disk('local')->assertExists($attachment->storage_path);
    }

    public function test_it_accepts_multiple_files_under_one_submission(): void
    {
        $admin = User::factory()->create();
        $player = Player::factory()->create();

        $response = $this->actingAs($admin)->post(
            "/players/{$player->id}/submissions",
            [
                'files' => [
                    UploadedFile::fake()->createWithContent(
                        'blood.pdf',
                        '%PDF-1.4 blood bytes',
                    ),
                    UploadedFile::fake()->createWithContent(
                        'inbody.pdf',
                        '%PDF-1.4 inbody bytes',
                    ),
                    UploadedFile::fake()->image('match_photo.jpg'),
                ],
            ],
        );

        $response->assertRedirect();
        $this->assertSame(1, Submission::count());
        $this->assertSame(3, Attachment::count());
        $this->assertSame(3, Submission::first()->attachments()->count());
    }

    public function test_it_rejects_a_missing_file_array(): void
    {
        $admin = User::factory()->create();
        $player = Player::factory()->create();

        $response = $this->actingAs($admin)->post(
            "/players/{$player->id}/submissions",
            ['files' => []],
        );

        $response->assertSessionHasErrors('files');
        $this->assertSame(0, Submission::count());
    }

    public function test_it_rejects_unsupported_mime_types(): void
    {
        $admin = User::factory()->create();
        $player = Player::factory()->create();

        $response = $this->actingAs($admin)->post(
            "/players/{$player->id}/submissions",
            [
                'files' => [
                    UploadedFile::fake()->createWithContent('virus.exe', 'MZ binary'),
                ],
            ],
        );

        $response->assertSessionHasErrors('files.0');
        $this->assertSame(0, Submission::count());
        $this->assertSame(0, Attachment::count());
    }

    public function test_it_rejects_an_invalid_category_hint(): void
    {
        $admin = User::factory()->create();
        $player = Player::factory()->create();

        $response = $this->actingAs($admin)->post(
            "/players/{$player->id}/submissions",
            [
                'files' => [
                    UploadedFile::fake()->createWithContent(
                        'ok.pdf',
                        '%PDF-1.4 ok '.uniqid(),
                    ),
                ],
                'category_hint' => 'not_a_real_category',
            ],
        );

        $response->assertSessionHasErrors('category_hint');
        $this->assertSame(0, Submission::count());
    }

    public function test_it_skips_duplicate_uploads_by_sha256_without_500ing(): void
    {
        $admin = User::factory()->create();
        $player = Player::factory()->create();
        $contents = '%PDF-1.4 unique blood content '.uniqid();

        // First upload — fresh, lands cleanly.
        $this->actingAs($admin)->post(
            "/players/{$player->id}/submissions",
            ['files' => [UploadedFile::fake()->createWithContent('blood.pdf', $contents)]],
        )->assertRedirect();

        $this->assertSame(1, Submission::count());
        $this->assertSame(1, Attachment::count());

        // Second upload of the same bytes — should not 500, should not
        // create a new submission, should leave attachment count at 1.
        $response = $this->actingAs($admin)->post(
            "/players/{$player->id}/submissions",
            ['files' => [UploadedFile::fake()->createWithContent('blood-renamed.pdf', $contents)]],
        );

        $response->assertRedirect();
        $response->assertSessionMissing('errors');
        $this->assertSame(1, Submission::count(), 'No new submission for an all-dupe upload.');
        $this->assertSame(1, Attachment::count());
    }

    public function test_it_processes_fresh_files_and_skips_duplicates_in_a_mixed_batch(): void
    {
        $admin = User::factory()->create();
        $player = Player::factory()->create();

        $shared = '%PDF-1.4 already on disk '.uniqid();

        // Seed an existing attachment with that content.
        $this->actingAs($admin)->post(
            "/players/{$player->id}/submissions",
            ['files' => [UploadedFile::fake()->createWithContent('seed.pdf', $shared)]],
        )->assertRedirect();

        $this->assertSame(1, Submission::count());
        $this->assertSame(1, Attachment::count());

        // Re-upload the same file alongside a new one — the new one
        // should land, the duplicate should be silently skipped.
        $this->actingAs($admin)->post(
            "/players/{$player->id}/submissions",
            ['files' => [
                UploadedFile::fake()->createWithContent('dup.pdf', $shared),
                UploadedFile::fake()->createWithContent('new.pdf', '%PDF-1.4 brand new content '.uniqid()),
            ]],
        )->assertRedirect();

        $this->assertSame(2, Submission::count(), 'A second submission is created for the new file.');
        $this->assertSame(2, Attachment::count(), 'The duplicate is skipped, only the new file lands.');
    }

    public function test_it_requires_authentication(): void
    {
        $player = Player::factory()->create();

        $response = $this->post(
            "/players/{$player->id}/submissions",
            [
                'files' => [
                    UploadedFile::fake()->createWithContent(
                        'ok.pdf',
                        '%PDF-1.4 ok '.uniqid(),
                    ),
                ],
            ],
        );

        $response->assertRedirect('/login');
        $this->assertSame(0, Submission::count());
    }
}
