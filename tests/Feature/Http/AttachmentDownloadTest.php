<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Attachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttachmentDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_authenticated_admin_can_stream_a_stored_attachment(): void
    {
        $admin = User::factory()->create();
        $attachment = Attachment::factory()->create([
            'storage_disk' => 'local',
            'storage_path' => 'attachments/1/2026-04/test.pdf',
            'mime_type' => 'application/pdf',
            'original_filename' => 'blood.pdf',
        ]);
        Storage::disk('local')->put($attachment->storage_path, '%PDF-1.4 fake bytes');

        $response = $this->actingAs($admin)->get("/attachments/{$attachment->id}");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('Content-Disposition');
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition') ?? '');
    }

    public function test_returns_404_when_the_underlying_file_is_missing(): void
    {
        $admin = User::factory()->create();
        $attachment = Attachment::factory()->create([
            'storage_disk' => 'local',
            'storage_path' => 'attachments/1/2026-04/missing.pdf',
        ]);
        // Note: NOT putting anything on disk — DB row exists, file doesn't.

        $this->actingAs($admin)
            ->get("/attachments/{$attachment->id}")
            ->assertNotFound();
    }

    public function test_unauthenticated_request_redirects_to_login(): void
    {
        $attachment = Attachment::factory()->create();

        $this->get("/attachments/{$attachment->id}")
            ->assertRedirect('/login');
    }
}
