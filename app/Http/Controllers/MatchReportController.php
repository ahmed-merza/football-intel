<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ApplyMatchReportRequest;
use App\Http\Requests\UploadMatchReportRequest;
use App\Jobs\ExtractMatchReportJob;
use App\Jobs\ExtractTextFromAttachmentJob;
use App\Models\Attachment;
use App\Models\MatchPerformance;
use App\Models\MatchReport;
use App\Models\Player;
use App\Models\Submission;
use App\Services\Match\MatchReportApplier;
use App\Services\Match\PlayerResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Match-report ingestion + resolution flow:
 *
 *   create() / store()    → upload form + receive PDF, kick off extraction
 *   preview($report)      → resolution UI (also covers in-flight/failed/applied states)
 *   apply($report)        → commit admin's resolutions to MatchPerformance + PlayerRecord
 *   retry($report)        → re-dispatch extraction for a failed report
 *   show($report)         → applied match view (minimal in Phase 1)
 *   index()               → list of all reports with status badges
 *   destroy($report)      → soft-delete a report + its derived rows
 *
 * Distinct from {@see SubmissionController} because match reports aren't
 * tied to a single player — they belong to a fixture and fan out to many
 * players. Both flows produce Submission + Attachment rows but the match
 * pipeline owns its own status lifecycle on `match_reports`.
 */
class MatchReportController extends Controller
{
    public function __construct(
        private readonly PlayerResolver $resolver,
        private readonly MatchReportApplier $applier,
    ) {}

    public function index(): Response
    {
        $reports = MatchReport::query()
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->map(fn (MatchReport $r): array => [
                'id' => $r->id,
                'status' => $r->status,
                'competition' => $r->competition,
                'stage' => $r->stage,
                'match_date' => $r->match_date?->toDateString(),
                'home_team_name' => $r->home_team_name,
                'away_team_name' => $r->away_team_name,
                'home_score' => $r->home_score,
                'away_score' => $r->away_score,
                'bahrain_side' => $r->bahrain_side,
                'opponent_name' => $r->opponent(),
                'created_at' => $r->created_at?->toIso8601String(),
                'applied_at' => $r->applied_at?->toIso8601String(),
                'extraction_error' => $r->extraction_error,
            ])
            ->all();

        return Inertia::render('matches/index', [
            'reports' => $reports,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('matches/upload');
    }

    /**
     * Accept the PDF upload, materialise the Submission + Attachment, create
     * the MatchReport row (status=pending), and dispatch the extract-text →
     * extract-match-report chain. Mirrors {@see SubmissionController::store}
     * minus the per-player binding — match reports aren't owned by a Player.
     *
     * sha256 dedupe up front — same PDF can't enter the system twice. The
     * partial unique index on `match_reports.attachment_id` is belt-and-braces.
     */
    public function store(UploadMatchReportRequest $request): RedirectResponse
    {
        /** @var UploadedFile $file */
        $file = $request->file('file');
        $sha = hash_file('sha256', $file->getRealPath());

        // If we already have this attachment AND it already has a match_report,
        // bounce the admin to that one instead of creating a duplicate.
        $existingAttachment = Attachment::where('sha256', $sha)->first();
        if ($existingAttachment !== null) {
            $existingReport = MatchReport::where('attachment_id', $existingAttachment->id)->first();
            if ($existingReport !== null) {
                Inertia::flash('toast', [
                    'type' => 'info',
                    'message' => __('This PDF has already been uploaded — opening the existing report.'),
                ]);

                return to_route('matches.preview', ['matchReport' => $existingReport->id]);
            }
        }

        $matchReport = DB::transaction(function () use ($request, $file, $sha, $existingAttachment): MatchReport {
            // A Submission is still useful for provenance + the existing
            // ExtractTextFromAttachmentJob expects an Attachment that belongs
            // to one. channel=manual_upload reuses the same enum value as
            // player-bound submissions; player_id is intentionally null here.
            $submission = Submission::create([
                'channel' => Submission::CHANNEL_MANUAL_UPLOAD,
                'player_id' => null,
                'uploaded_by' => $request->user()?->id,
                'received_at' => Carbon::now(),
                'status' => Submission::STATUS_PROCESSING,
                'notes' => 'match_report_upload',
            ]);

            // When the attachment is fresh, persistAttachment links it to
            // the match-flow submission. When the sha256 already exists
            // (e.g. the same PDF was earlier uploaded against a player and
            // landed as category=other), we reuse the attachment but
            // intentionally LEAVE its original submission_id alone — the
            // match_report itself carries the match-flow submission_id, so
            // both intake histories stay intact.
            $attachment = $existingAttachment
                ?? $this->persistAttachment($file, $sha, $submission);

            return MatchReport::create([
                'source' => MatchReport::SOURCE_AGCFF,
                'submission_id' => $submission->id,
                'attachment_id' => $attachment->id,
                'uploaded_by' => $request->user()?->id,
                'status' => MatchReport::STATUS_PENDING,
            ]);
        });

        // Extract text first (PDF → raw text via smalot + OCR fallback), then
        // run the match-report extractor. Chain ensures the extractor only
        // fires after the text is populated.
        Bus::chain([
            new ExtractTextFromAttachmentJob((int) $matchReport->attachment_id),
            new ExtractMatchReportJob($matchReport->id),
        ])->dispatch();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Match report uploaded — extraction running.'),
        ]);

        return to_route('matches.preview', ['matchReport' => $matchReport->id]);
    }

    /**
     * Workhorse screen. Conditional on status:
     *
     *   pending / extracting / awaiting_callback  → "Processing" panel (polls)
     *   extracted                                  → Resolution UI
     *   applied                                    → redirect to show()
     *   failed                                     → error panel with Retry button
     */
    public function preview(MatchReport $matchReport): Response|RedirectResponse
    {
        if ($matchReport->status === MatchReport::STATUS_APPLIED) {
            return to_route('matches.show', ['matchReport' => $matchReport->id]);
        }

        $performances = [];
        $activePlayers = [];

        if ($matchReport->status === MatchReport::STATUS_EXTRACTED) {
            $performances = $this->buildPerformancesForResolution($matchReport);
            $activePlayers = $this->buildActivePlayersList();
        }

        return Inertia::render('matches/preview', [
            'match' => $this->matchSummary($matchReport),
            'performances' => $performances,
            'active_players' => $activePlayers,
        ]);
    }

    /**
     * Commit the admin's resolutions to MatchPerformance + PlayerRecord
     * (+ record_metrics fan-out) atomically via {@see MatchReportApplier}.
     * Idempotent — re-applying with edited resolutions wipes the prior
     * fan-out first.
     */
    public function apply(ApplyMatchReportRequest $request, MatchReport $matchReport): RedirectResponse
    {
        if ($matchReport->status !== MatchReport::STATUS_EXTRACTED) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('This report is not ready to apply — current state: :state.', ['state' => $matchReport->status]),
            ]);

            return back();
        }

        /** @var array<int, array<string, mixed>> $resolutions */
        $resolutions = $request->validated('resolutions', []);

        $this->applier->applyResolution($matchReport, $resolutions);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Match report applied — performances are now on the players\' timelines.'),
        ]);

        return to_route('matches.show', ['matchReport' => $matchReport->id]);
    }

    /**
     * Re-dispatch extraction on a failed report. Wipes the extraction_error
     * + flips status back to pending so the UI doesn't keep showing the
     * stale failure. Also fires on `awaiting_callback` rows that have been
     * stuck longer than the reaper's window — admin clicks "Try again" to
     * force a fresh sync attempt.
     */
    public function retry(MatchReport $matchReport): RedirectResponse
    {
        if (! in_array($matchReport->status, [MatchReport::STATUS_FAILED, MatchReport::STATUS_AWAITING_CALLBACK], true)) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Only failed or stuck reports can be retried.'),
            ]);

            return back();
        }

        $matchReport->update([
            'status' => MatchReport::STATUS_PENDING,
            'extraction_error' => null,
        ]);

        Bus::chain([
            new ExtractTextFromAttachmentJob((int) $matchReport->attachment_id),
            new ExtractMatchReportJob($matchReport->id),
        ])->dispatch();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Retry queued.'),
        ]);

        return back();
    }

    /**
     * Minimal applied-match view. Phase 1 lists the resolved performances
     * with their ratings + minutes + headline stats; Phase 2 will add
     * lineups + team totals + head-to-head.
     */
    public function show(MatchReport $matchReport): Response
    {
        $matchReport->load(['performances.player' => function ($q): void {
            $q->select(['id', 'full_name']);
        }]);

        $performances = $matchReport->performances
            ->map(fn (MatchPerformance $p): array => [
                'id' => $p->id,
                'team_side' => $p->team_side,
                'reported_name' => $p->reported_name,
                'jersey_number' => $p->jersey_number,
                'match_position' => $p->match_position,
                'appearance' => $p->appearance,
                'minutes_played' => $p->minutes_played,
                'rating' => $p->rating !== null ? (float) $p->rating : null,
                'goals' => $p->goals,
                'assists' => $p->assists,
                'pass_accuracy_pct' => $p->pass_accuracy_pct !== null ? (float) $p->pass_accuracy_pct : null,
                'player' => $p->player ? [
                    'id' => $p->player->id,
                    'full_name' => $p->player->full_name,
                ] : null,
            ])
            ->values()
            ->all();

        return Inertia::render('matches/show', [
            'match' => $this->matchSummary($matchReport),
            'performances' => $performances,
        ]);
    }

    /**
     * Soft-delete a report. The match_performances cascade (cascadeOnDelete
     * on the FK is hard-delete, but the report itself uses softDeletesTz so
     * the audit row stays); resolved PlayerRecords are preserved — the admin
     * intent is "this match shouldn't appear in the list", not "wipe these
     * players' history".
     */
    public function destroy(MatchReport $matchReport): RedirectResponse
    {
        $matchReport->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Match report removed.'),
        ]);

        return to_route('matches.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function matchSummary(MatchReport $report): array
    {
        return [
            'id' => $report->id,
            'status' => $report->status,
            'source' => $report->source,
            'competition' => $report->competition,
            'stage' => $report->stage,
            'match_date' => $report->match_date?->toDateString(),
            'kickoff_time' => $report->kickoff_time,
            'venue' => $report->venue,
            'home_team_name' => $report->home_team_name,
            'away_team_name' => $report->away_team_name,
            'home_score' => $report->home_score,
            'away_score' => $report->away_score,
            'bahrain_side' => $report->bahrain_side,
            'opponent_name' => $report->opponent(),
            'extraction_error' => $report->extraction_error,
            'extracted_at' => $report->extracted_at?->toIso8601String(),
            'applied_at' => $report->applied_at?->toIso8601String(),
        ];
    }

    /**
     * Combine each extracted performance with PlayerResolver suggestions so
     * the admin's dropdown is pre-populated. Opposition rows are still
     * surfaced (the admin sees the team-level data) but with no resolution
     * controls — they always store as opponent.
     *
     * @return list<array<string, mixed>>
     */
    private function buildPerformancesForResolution(MatchReport $report): array
    {
        /** @var array<int, array<string, mixed>> $raw */
        $raw = is_array($report->raw_extracted) ? ($report->raw_extracted['performances'] ?? []) : [];

        $bahrainSide = $report->bahrain_side;

        return collect($raw)
            ->values()
            ->map(function (array $perf, int $index) use ($bahrainSide): array {
                $extractorSide = is_string($perf['team_side'] ?? null) ? $perf['team_side'] : null;
                $teamSide = $extractorSide === $bahrainSide
                    ? MatchPerformance::TEAM_SIDE_BAHRAIN
                    : MatchPerformance::TEAM_SIDE_OPPONENT;

                // Build the input shape PlayerResolver expects (its own
                // contract is narrower than the full extractor row).
                $resolverInput = [
                    'reported_name' => is_string($perf['reported_name'] ?? null) ? $perf['reported_name'] : null,
                    'jersey_number' => is_numeric($perf['jersey_number'] ?? null) ? (int) $perf['jersey_number'] : null,
                    'match_position' => is_string($perf['match_position'] ?? null) ? $perf['match_position'] : null,
                    'team_side' => $teamSide,
                ];

                return [
                    'index' => $index,
                    'team_side' => $teamSide,
                    'reported_name' => $resolverInput['reported_name'],
                    'jersey_number' => $resolverInput['jersey_number'],
                    'match_position' => $resolverInput['match_position'],
                    'appearance' => is_string($perf['appearance'] ?? null) ? $perf['appearance'] : MatchPerformance::APPEARANCE_STARTER,
                    'minutes_played' => is_numeric($perf['minutes_played'] ?? null) ? (int) $perf['minutes_played'] : null,
                    'rating' => is_numeric($perf['rating'] ?? null) ? (float) $perf['rating'] : null,
                    'goals' => is_numeric($perf['goals'] ?? null) ? (int) $perf['goals'] : 0,
                    'assists' => is_numeric($perf['assists'] ?? null) ? (int) $perf['assists'] : 0,
                    'suggestions' => $this->resolver->suggest($resolverInput),
                ];
            })
            ->all();
    }

    /**
     * Cheap full list of active players for the manual-pick dropdown. The
     * squad fits in a small array so we just ship the whole thing rather
     * than build an autocomplete endpoint.
     *
     * @return list<array{id: int, full_name: string, position: string|null, club: string|null}>
     */
    private function buildActivePlayersList(): array
    {
        return Player::query()
            ->where('status', Player::STATUS_ACTIVE)
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'position', 'club'])
            ->map(fn (Player $p): array => [
                'id' => $p->id,
                'full_name' => $p->full_name,
                'position' => $p->position,
                'club' => $p->club,
            ])
            ->all();
    }

    private function persistAttachment(UploadedFile $file, string $sha256, Submission $submission): Attachment
    {
        $extension = $file->getClientOriginalExtension()
            ?: ($file->guessExtension() ?? 'pdf');
        $uuid = Str::uuid()->toString();
        $monthFolder = Carbon::now()->format('Y-m');
        $relativePath = "attachments/match-reports/{$monthFolder}/{$uuid}.{$extension}";

        Storage::disk('local')->putFileAs(
            dirname($relativePath),
            $file,
            basename($relativePath),
        );

        return Attachment::create([
            'submission_id' => $submission->id,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?? 'application/pdf',
            'size_bytes' => $file->getSize() ?: 0,
            'storage_disk' => 'local',
            'storage_path' => $relativePath,
            'sha256' => $sha256,
            'page_count' => null,
            'is_text_pdf' => null,
            'extracted_text' => null,
            'ocr_used' => false,
            'ocr_confidence' => null,
            'thumbnail_path' => null,
        ]);
    }
}
