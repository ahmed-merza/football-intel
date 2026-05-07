<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Agents\DocumentClassifier;
use App\Models\Attachment;
use App\Models\PlayerRecord;
use App\Models\RecordCategory;
use App\Models\Submission;
use App\Services\Ai\AgentRouter;
use App\Services\Ai\TextPreprocessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Step 2 of the ingestion pipeline: route an attachment to its category.
 *
 * Depends on ExtractTextFromAttachmentJob having populated
 * attachments.extracted_text first. Creates a PlayerRecord with the
 * classifier's pick, then promotes the parent Submission's status:
 *   - classified   — high-confidence category, ready for the category
 *                    extractor (future job)
 *   - needs_review — no text extracted, low confidence, or classifier
 *                    picked 'other'; admin intervenes
 *   - failed       — unrecoverable error (exception bubbles up)
 *
 * If the admin provided a category hint at upload time and the classifier
 * disagrees with confidence < 0.9, we trust the admin's hint but still log
 * the divergence on the record for later review.
 */
class ClassifyAttachmentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const HIGH_CONFIDENCE_THRESHOLD = 0.7;

    public function __construct(public int $attachmentId)
    {
        $this->onQueue('llm');
    }

    /**
     * Belt-and-braces for failures the try/catch can't see — timeout
     * kills (the worker signals the child from outside the PHP call
     * stack), OOM, worker restarts mid-handle. Without this, a
     * timed-out classifier leaves the submission stuck in `processing`
     * forever and the admin has no way to tell from the UI.
     */
    public function failed(Throwable $exception): void
    {
        $attachment = Attachment::find($this->attachmentId);
        $attachment?->submission?->update([
            'status' => Submission::STATUS_FAILED,
        ]);
    }

    public function handle(TextPreprocessor $preprocessor, AgentRouter $router): void
    {
        /** @var Attachment|null $attachment */
        $attachment = Attachment::with('submission')->find($this->attachmentId);
        if ($attachment === null || $attachment->submission === null) {
            return;
        }

        $submission = $attachment->submission;

        if (empty($attachment->extracted_text)) {
            // No text → can't classify automatically. Route to review.
            $submission->update(['status' => Submission::STATUS_NEEDS_REVIEW]);

            return;
        }

        // See App\Services\Ai\TextPreprocessor — strip_arabic + truncation
        // are configurable per env (AI_STRIP_ARABIC_CLASSIFIER,
        // AI_CLASSIFIER_MAX_CHARS).
        $prepared = $preprocessor->forClassifier($attachment->extracted_text);

        try {
            $result = $this->classify($prepared, $router);
        } catch (Throwable $e) {
            Log::error('DocumentClassifier threw', [
                'attachment_id' => $attachment->id,
                'exception' => $e->getMessage(),
            ]);
            $submission->update(['status' => Submission::STATUS_FAILED]);

            throw $e;
        }

        $adminHint = $this->extractAdminHint($submission->notes);
        $chosen = $this->resolveCategory($adminHint, $result);

        $record = DB::transaction(function () use ($attachment, $submission, $chosen, $result): PlayerRecord {
            $category = RecordCategory::where('slug', $chosen)->firstOrFail();

            $record = PlayerRecord::create([
                'player_id' => $submission->player_id,
                'category_id' => $category->id,
                'record_date' => Carbon::parse($submission->received_at)->toDateString(),
                'submission_id' => $submission->id,
                'primary_attachment_id' => $attachment->id,
                // Minimal payload until ExtractStructuredDataJob runs and
                // replaces it with typed category-specific data.
                'extracted' => [
                    'classifier' => $result,
                    'needs_structured_extraction' => true,
                ],
                'summary_text' => $result['reasoning'],
                'reviewed' => false,
            ]);

            $isLowConfidence = $result['confidence'] < self::HIGH_CONFIDENCE_THRESHOLD;
            $isOther = $chosen === RecordCategory::OTHER;

            $submission->update([
                'status' => $isLowConfidence || $isOther
                    ? Submission::STATUS_NEEDS_REVIEW
                    : Submission::STATUS_CLASSIFIED,
                'processed_at' => Carbon::now(),
            ]);

            return $record;
        });

        // Structured extraction runs only on high-confidence classifications.
        // Low-confidence / "other" records stay on the timeline with the
        // classifier-minimal payload and surface in the review queue.
        if ($submission->fresh()->status === Submission::STATUS_CLASSIFIED) {
            ExtractStructuredDataJob::dispatch($record->id);
        }
    }

    /**
     * @return array{category: string, confidence: float, reasoning: string}
     */
    private function classify(string $text, AgentRouter $router): array
    {
        /** @var array{category: string, confidence: float, reasoning: string} $result */
        $result = $router->send(new DocumentClassifier, $text);

        return $result;
    }

    private function extractAdminHint(?string $notes): ?string
    {
        if ($notes === null) {
            return null;
        }

        foreach (explode("\n", $notes) as $line) {
            if (str_starts_with($line, 'hint:')) {
                return substr($line, 5);
            }
        }

        return null;
    }

    /**
     * If the admin tagged a hint at upload time, trust it unless the
     * classifier is very confident it disagrees (≥ 0.9). This gives the
     * admin ultimate authority while still catching obvious misclicks.
     *
     * @param  array{category?: string, confidence?: float}  $result
     */
    private function resolveCategory(?string $adminHint, array $result): string
    {
        $predicted = $result['category'] ?? RecordCategory::OTHER;
        $confidence = $result['confidence'] ?? 0.0;

        if ($adminHint === null) {
            return $predicted;
        }

        if ($adminHint === $predicted) {
            return $predicted;
        }

        return $confidence >= 0.9 ? $predicted : $adminHint;
    }
}
