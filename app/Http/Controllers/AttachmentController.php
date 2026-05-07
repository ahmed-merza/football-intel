<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Auth-gated file streaming for attachments stored on the private disk
 * (medical PDFs never sit on the public disk). The Documents tab points
 * at this endpoint instead of exposing the raw filesystem path.
 */
class AttachmentController extends Controller
{
    /**
     * Stream the attachment inline so PDFs/images preview in a browser
     * tab. Force-download isn't useful here — every consumer wants to
     * eyeball the file, not save it locally.
     */
    public function download(Attachment $attachment): StreamedResponse
    {
        $disk = Storage::disk($attachment->storage_disk);
        abort_unless($disk->exists($attachment->storage_path), 404);

        return $disk->response(
            $attachment->storage_path,
            $attachment->original_filename,
            ['Content-Type' => $attachment->mime_type ?: 'application/octet-stream'],
            'inline',
        );
    }
}
