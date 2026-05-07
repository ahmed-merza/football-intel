<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Attachment;
use App\Models\Submission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;

/**
 * Step 1 of the ingestion pipeline: pull plain text out of the attachment.
 *
 * Handles text-layer PDFs via smalot/pdfparser. When a PDF has no text
 * layer, or the attachment is an image / non-PDF, the job writes
 * `is_text_pdf=false` and leaves `extracted_text` null; the classification
 * step then routes the submission to needs_review so the admin can
 * intervene.
 */
class ExtractTextFromAttachmentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $attachmentId)
    {
        $this->onQueue('ocr');
    }

    /**
     * Runs after all retries are exhausted for any reason — caught
     * exception, timeout kill, OOM, worker restart mid-handle. Ensures
     * the submission never gets stranded in `processing` when the chain
     * can't recover on its own. The try/catch inside handle() can't see
     * timeout kills because the worker signals the child process from
     * outside the PHP call stack.
     */
    public function failed(Throwable $exception): void
    {
        $attachment = Attachment::find($this->attachmentId);
        $attachment?->submission?->update([
            'status' => Submission::STATUS_FAILED,
        ]);
    }

    public function handle(PdfParser $parser): void
    {
        /** @var Attachment|null $attachment */
        $attachment = Attachment::find($this->attachmentId);
        if ($attachment === null) {
            // Deleted between upload and job run — nothing to do.
            return;
        }

        if (! str_starts_with($attachment->mime_type, 'application/pdf')) {
            // Only PDFs run through pdfparser. Non-PDFs are marked so the
            // classifier routes them to needs_review.
            $attachment->update(['is_text_pdf' => false]);

            return;
        }

        try {
            $absolute = Storage::disk($attachment->storage_disk)->path($attachment->storage_path);
            $document = $parser->parseFile($absolute);
            // smalot/pdfparser leaks UTF-16 BE bytes for some embedded
            // text sections (Arabic interpretation blocks on Al-Kindi
            // panels, for instance) — every Latin char ends up paired
            // with a NULL byte. NULL bytes are illegal in Postgres
            // JSONB columns we later persist this through, so strip
            // them here. Side benefit: the UTF-16 sections fall back
            // to readable ASCII once the high bytes are gone.
            $text = trim(str_replace("\0", '', $document->getText()));
            $pageCount = count($document->getPages());

            $attachment->update([
                'extracted_text' => $text !== '' ? $text : null,
                'is_text_pdf' => $text !== '',
                'page_count' => $pageCount,
                'ocr_used' => false,
            ]);
        } catch (Throwable $e) {
            Log::warning('PDF text extraction failed', [
                'attachment_id' => $attachment->id,
                'exception' => $e->getMessage(),
            ]);
            $attachment->update(['is_text_pdf' => false]);
        }
    }
}
