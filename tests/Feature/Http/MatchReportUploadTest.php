<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Jobs\ExtractMatchReportJob;
use App\Jobs\ExtractTextFromAttachmentJob;
use App\Models\Attachment;
use App\Models\MatchReport;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Covers POST /matches/upload — the entry point for the whole match-report
 * pipeline. Mirrors {@see SubmissionUploadTest}'s isolation pattern: fake
 * the bus (the extract chain has its own tests), fake the disk (so we don't
 * litter the real attachments directory).
 */
class MatchReportUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Bus::fake();
    }

    public function test_it_creates_submission_attachment_and_match_report_then_dispatches_chain(): void
    {
        $admin = User::factory()->create();

        $response = $this->actingAs($admin)->post('/matches/upload', [
            'file' => UploadedFile::fake()->createWithContent(
                'agcff_match.pdf',
                '%PDF-1.4 fake match report '.uniqid(),
            ),
        ]);

        $report = MatchReport::latest('id')->firstOrFail();
        $response->assertRedirect("/matches/{$report->id}/preview");

        $this->assertSame(MatchReport::STATUS_PENDING, $report->status);
        $this->assertSame(MatchReport::SOURCE_AGCFF, $report->source);
        $this->assertSame($admin->id, $report->uploaded_by);
        $this->assertNull($report->raw_extracted);

        // Sibling Submission + Attachment landed under it.
        $this->assertSame(1, Submission::count());
        $this->assertSame(1, Attachment::count());

        $attachment = Attachment::firstOrFail();
        $this->assertSame($report->attachment_id, $attachment->id);
        $this->assertStringStartsWith('attachments/match-reports/', $attachment->storage_path);
        Storage::disk('local')->assertExists($attachment->storage_path);

        // Extract-text → match-report extractor chain dispatched.
        Bus::assertChained([
            ExtractTextFromAttachmentJob::class,
            ExtractMatchReportJob::class,
        ]);
    }

    public function test_it_rejects_non_pdf_uploads(): void
    {
        $admin = User::factory()->create();

        $response = $this->actingAs($admin)->post('/matches/upload', [
            'file' => UploadedFile::fake()->image('snapshot.jpg'),
        ]);

        $response->assertSessionHasErrors('file');
        $this->assertSame(0, MatchReport::count());
    }

    public function test_it_rejects_missing_file(): void
    {
        $admin = User::factory()->create();

        $response = $this->actingAs($admin)->post('/matches/upload', []);

        $response->assertSessionHasErrors('file');
        $this->assertSame(0, MatchReport::count());
    }

    public function test_re_uploading_the_same_pdf_redirects_to_existing_report(): void
    {
        $admin = User::factory()->create();
        $contents = '%PDF-1.4 stable bytes '.uniqid();

        // First upload — lands cleanly.
        $this->actingAs($admin)->post('/matches/upload', [
            'file' => UploadedFile::fake()->createWithContent('first.pdf', $contents),
        ])->assertRedirect();

        $firstReport = MatchReport::firstOrFail();
        $this->assertSame(1, MatchReport::count());

        // Re-upload the same bytes — should NOT create a second report,
        // should redirect to the existing one.
        $response = $this->actingAs($admin)->post('/matches/upload', [
            'file' => UploadedFile::fake()->createWithContent('renamed.pdf', $contents),
        ]);

        $response->assertRedirect("/matches/{$firstReport->id}/preview");
        $this->assertSame(1, MatchReport::count(), 'Re-upload must not create a second match_report.');
        $this->assertSame(1, Attachment::count());
    }

    public function test_it_requires_authentication(): void
    {
        $response = $this->post('/matches/upload', [
            'file' => UploadedFile::fake()->createWithContent('x.pdf', '%PDF-1.4 x'),
        ]);

        $response->assertRedirect('/login');
        $this->assertSame(0, MatchReport::count());
    }

    public function test_index_lists_recent_reports_with_their_status(): void
    {
        $admin = User::factory()->create();
        MatchReport::factory()->create(['status' => MatchReport::STATUS_EXTRACTED]);
        MatchReport::factory()->applied()->create();
        MatchReport::factory()->failed()->create();

        $this->actingAs($admin)
            ->get('/matches')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('matches/index')
                ->has('reports', 3)
            );
    }

    public function test_create_renders_the_upload_form(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->get('/matches/upload')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('matches/upload'));
    }
}
