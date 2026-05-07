<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreSubmissionRequest;
use App\Jobs\ClassifyAttachmentJob;
use App\Jobs\ExtractTextFromAttachmentJob;
use App\Models\Attachment;
use App\Models\Player;
use App\Models\Submission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class SubmissionController extends Controller
{
    /**
     * Accept one or more uploaded files for a player, persist the submission
     * + attachments, and drop the raw files on the private disk at
     * attachments/{player_id}/{YYYY-MM}/{uuid}.{ext}.
     *
     * The submission lands in status=processing — downstream jobs
     * (ExtractTextFromAttachment → Classify → Extract → Analyse) promote it
     * to classified / needs_review / failed from there.
     */
    public function store(StoreSubmissionRequest $request, Player $player): RedirectResponse
    {
        $files = $request->file('files');
        $files = is_array($files) ? $files : [$files];
        $monthFolder = Carbon::now()->format('Y-m');

        // Hash + dedupe BEFORE we open a transaction. The partial unique
        // index `attachments_sha256_unique_active` would have rejected
        // dupes anyway, but rejecting at the schema level rolls back the
        // entire submission and surfaces a 500 — bad UX. Splitting up
        // front lets us cleanly skip + flash a friendly message.
        $partition = $this->partitionFilesBySha($files);

        if ($partition['fresh'] === []) {
            // Every uploaded file is already in the system. No submission
            // to create; just tell the admin and bounce.
            Inertia::flash('toast', [
                'type' => 'info',
                'message' => trans_choice(
                    '{1} :count file is already on this system, nothing to do.|[2,*] :count files are already on this system, nothing to do.',
                    count($partition['duplicates']),
                    ['count' => count($partition['duplicates'])],
                ),
            ]);

            return back();
        }

        $attachmentIds = DB::transaction(function () use ($request, $player, $partition, $monthFolder): array {
            $submission = Submission::create([
                'channel' => Submission::CHANNEL_MANUAL_UPLOAD,
                'player_id' => $player->id,
                'uploaded_by' => $request->user()?->id,
                'received_at' => Carbon::now(),
                'status' => Submission::STATUS_PROCESSING,
                'notes' => $this->buildNotes($request->input('category_hint'), $request->input('notes')),
            ]);

            $ids = [];
            foreach ($partition['fresh'] as $entry) {
                $attachment = $this->persistAttachment(
                    $entry['file'],
                    $entry['sha256'],
                    $submission,
                    $player,
                    $monthFolder,
                );
                $ids[] = $attachment->id;
            }

            return $ids;
        });

        // One job chain per attachment — extract → classify. Chains run
        // sequentially so classification always sees the text the extractor
        // wrote, and each attachment fans out independently (one failure
        // doesn't stall the others).
        foreach ($attachmentIds as $id) {
            Bus::chain([
                new ExtractTextFromAttachmentJob($id),
                new ClassifyAttachmentJob($id),
            ])->dispatch();
        }

        $newCount = count($partition['fresh']);
        $dupeCount = count($partition['duplicates']);

        $message = $dupeCount === 0
            ? trans_choice(
                '{1} :count file uploaded — processing queued.|[2,*] :count files uploaded — processing queued.',
                $newCount,
                ['count' => $newCount],
            )
            : trans_choice(
                '{1} :new file uploaded, :dupe duplicate skipped.|[2,*] :new files uploaded, :dupe duplicates skipped.',
                $newCount,
                ['new' => $newCount, 'dupe' => $dupeCount],
            );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $message,
        ]);

        return back();
    }

    /**
     * Split incoming files into "fresh" (sha256 not yet in attachments)
     * vs "duplicates". Hashes the file content (not the client-supplied
     * mime) so a duplicate can't masquerade by renaming. We also skip
     * collisions WITHIN the same upload so the second copy of an
     * identical drag-drop doesn't sneak past.
     *
     * @param  list<UploadedFile>  $files
     * @return array{fresh: list<array{file: UploadedFile, sha256: string}>, duplicates: list<UploadedFile>}
     */
    private function partitionFilesBySha(array $files): array
    {
        $fresh = [];
        $duplicates = [];
        $seenInBatch = [];

        foreach ($files as $file) {
            $sha = hash_file('sha256', $file->getRealPath());

            if (isset($seenInBatch[$sha])) {
                $duplicates[] = $file;

                continue;
            }

            $existsOnDisk = Attachment::where('sha256', $sha)->exists();
            if ($existsOnDisk) {
                $duplicates[] = $file;

                continue;
            }

            $seenInBatch[$sha] = true;
            $fresh[] = ['file' => $file, 'sha256' => $sha];
        }

        return ['fresh' => $fresh, 'duplicates' => $duplicates];
    }

    /**
     * Retry a failed submission — re-kicks the extract → classify chain
     * for every attachment on it. Also allowed on "processing" rows that
     * have been stuck for a while (a worker may have died mid-chain).
     * We wipe any partial state (extracted_text + classifier-built
     * PlayerRecord rows) so the chain restarts from a clean slate.
     */
    public function retry(Submission $submission): RedirectResponse
    {
        if (! in_array($submission->status, [Submission::STATUS_FAILED, Submission::STATUS_PROCESSING], true)) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Only failed or stuck submissions can be retried.'),
            ]);

            return back();
        }

        DB::transaction(function () use ($submission): void {
            // Drop half-built PlayerRecords + their metric fan-out so the
            // chain doesn't create duplicates on re-run.
            $submission->records()->each(fn ($record) => $record->forceDelete());

            // Reset each attachment to pre-extraction state.
            $submission->attachments()->update([
                'extracted_text' => null,
                'is_text_pdf' => null,
                'page_count' => null,
                'ocr_used' => false,
                'ocr_confidence' => null,
            ]);

            $submission->update([
                'status' => Submission::STATUS_PROCESSING,
                'processed_at' => null,
            ]);
        });

        foreach ($submission->attachments()->pluck('id') as $id) {
            Bus::chain([
                new ExtractTextFromAttachmentJob((int) $id),
                new ClassifyAttachmentJob((int) $id),
            ])->dispatch();
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Retry queued.'),
        ]);

        return back();
    }

    /**
     * Discard a failed submission — soft-deletes the submission + its
     * attachments + any partial PlayerRecords, and removes the raw files
     * from disk. Only allowed on `failed` submissions so the admin can't
     * accidentally nuke a classified / reviewed one. A future hard-purge
     * flow handles already-classified data.
     */
    public function destroy(Submission $submission): RedirectResponse
    {
        if ($submission->status !== Submission::STATUS_FAILED) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Only failed submissions can be discarded here.'),
            ]);

            return back();
        }

        DB::transaction(function () use ($submission): void {
            foreach ($submission->attachments as $attachment) {
                Storage::disk($attachment->storage_disk)->delete($attachment->storage_path);
                $attachment->delete();
            }
            $submission->records()->each(fn ($r) => $r->forceDelete());
            $submission->delete();
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Failed submission discarded.'),
        ]);

        return back();
    }

    private function persistAttachment(
        UploadedFile $file,
        string $sha256,
        Submission $submission,
        Player $player,
        string $monthFolder,
    ): Attachment {
        $extension = $file->getClientOriginalExtension()
            ?: $file->guessExtension()
            ?? 'bin';
        $uuid = Str::uuid()->toString();
        $relativePath = "attachments/{$player->id}/{$monthFolder}/{$uuid}.{$extension}";

        Storage::disk('local')->putFileAs(
            dirname($relativePath),
            $file,
            basename($relativePath),
        );

        return Attachment::create([
            'submission_id' => $submission->id,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size_bytes' => $file->getSize() ?: 0,
            'storage_disk' => 'local',
            'storage_path' => $relativePath,
            'sha256' => $sha256,
            // page_count + is_text_pdf + extracted_text are populated by the
            // ExtractTextFromAttachment job; null here means unprocessed.
            'page_count' => null,
            'is_text_pdf' => null,
            'extracted_text' => null,
            'ocr_used' => false,
            'ocr_confidence' => null,
            'thumbnail_path' => null,
        ]);
    }

    private function buildNotes(?string $categoryHint, ?string $notes): ?string
    {
        $parts = array_filter([
            $categoryHint !== null ? "hint:{$categoryHint}" : null,
            $notes,
        ]);

        return $parts === [] ? null : implode("\n", $parts);
    }
}
