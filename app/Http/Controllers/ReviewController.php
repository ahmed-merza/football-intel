<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ExtractStructuredDataJob;
use App\Models\PlayerRecord;
use App\Models\RecordCategory;
use App\Models\Submission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin inbox for low-confidence classifications + misclassified records.
 *
 * Queue membership: PlayerRecord where the parent Submission is
 * status=needs_review AND the record itself hasn't been reviewed yet.
 * Confirm() flips reviewed=true, optionally overrides the category, then
 * re-kicks structured extraction if the category matches an extractor we
 * have wired. The parent submission gets promoted to classified once
 * every record belonging to it has been reviewed.
 */
class ReviewController extends Controller
{
    public function index(): Response
    {
        $records = PlayerRecord::query()
            ->with(['player:id,full_name,name_ar,photo_path', 'category', 'primaryAttachment:id,original_filename,page_count', 'submission:id,status,received_at,notes'])
            ->whereHas('submission', fn ($q) => $q->where('status', Submission::STATUS_NEEDS_REVIEW))
            ->where('reviewed', false)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return Inertia::render('review/index', [
            'records' => $records->map(fn (PlayerRecord $r): array => [
                'id' => $r->id,
                'created_at' => $r->created_at?->toIso8601String(),
                'record_date' => $r->record_date->toDateString(),
                'summary_text' => $r->summary_text,
                'classifier' => [
                    'category' => $r->extracted['classifier']['category'] ?? null,
                    'confidence' => $r->extracted['classifier']['confidence'] ?? null,
                    'reasoning' => $r->extracted['classifier']['reasoning'] ?? null,
                ],
                'admin_hint' => $this->extractAdminHint($r->submission?->notes),
                'category' => [
                    'id' => $r->category->id,
                    'slug' => $r->category->slug,
                    'label' => $r->category->label_en,
                ],
                'player' => [
                    'id' => $r->player->id,
                    'full_name' => $r->player->full_name,
                    'name_ar' => $r->player->name_ar,
                    'photo_url' => $r->player->photo_path !== null
                        ? asset('storage/'.$r->player->photo_path)
                        : null,
                ],
                'attachment' => $r->primaryAttachment !== null ? [
                    'id' => $r->primaryAttachment->id,
                    'filename' => $r->primaryAttachment->original_filename,
                    'page_count' => $r->primaryAttachment->page_count,
                ] : null,
            ])->all(),
            'categories' => RecordCategory::orderBy('sort_order')->get(['id', 'slug', 'label_en'])
                ->map(fn (RecordCategory $c): array => [
                    'id' => $c->id,
                    'slug' => $c->slug,
                    'label' => $c->label_en,
                ])->all(),
            'count' => $records->count(),
        ]);
    }

    public function confirm(Request $request, PlayerRecord $record): RedirectResponse
    {
        $data = $request->validate([
            'category_slug' => ['nullable', Rule::in(array_map(
                fn (RecordCategory $c): string => $c->slug,
                RecordCategory::all()->all(),
            ))],
        ]);

        $categoryChanged = false;

        DB::transaction(function () use ($request, $record, $data, &$categoryChanged): void {
            if (! empty($data['category_slug'])) {
                $category = RecordCategory::where('slug', $data['category_slug'])->firstOrFail();
                if ($record->category_id !== $category->id) {
                    $record->category_id = $category->id;
                    $categoryChanged = true;
                }
            }

            $record->reviewed = true;
            $record->reviewed_at = now();
            $record->reviewed_by = $request->user()?->id;
            $record->save();

            // Once every record on the submission has been reviewed, promote
            // the submission so it disappears from the queue.
            if ($record->submission !== null) {
                $remaining = PlayerRecord::where('submission_id', $record->submission_id)
                    ->where('reviewed', false)
                    ->count();
                if ($remaining === 0) {
                    $record->submission->update(['status' => Submission::STATUS_CLASSIFIED]);
                }
            }
        });

        // If the admin corrected the category into something we can extract,
        // re-kick the structured extraction — the old extracted payload may
        // be wrong for the new category.
        if ($categoryChanged) {
            ExtractStructuredDataJob::dispatch($record->id);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Record reviewed.')]);

        return back();
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
}
