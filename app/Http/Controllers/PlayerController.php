<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\PlayerData;
use App\Http\Requests\StorePlayerRequest;
use App\Http\Requests\UpdatePlayerRequest;
use App\Models\Alert;
use App\Models\Player;
use App\Models\PlayerRecord;
use App\Models\RecordCategory;
use App\Models\RecordMetric;
use App\Models\Submission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class PlayerController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    /**
     * JSON endpoint for the command palette — returns up to 8 lightweight
     * matches. Uses ILIKE across name + Arabic name + club + player_code so
     * the admin can jump by whichever identifier they remember first.
     *
     * @return array{players: array<int, array<string, mixed>>}
     */
    public function search(Request $request): array
    {
        $term = (string) $request->query('q', '');

        if ($term === '') {
            return ['players' => []];
        }

        $rows = Player::active()
            ->where(function (Builder $q) use ($term): void {
                $like = '%'.$term.'%';
                $q->where('full_name', 'ILIKE', $like)
                    ->orWhere('name_ar', 'ILIKE', $like)
                    ->orWhere('club', 'ILIKE', $like)
                    ->orWhere('player_code', 'ILIKE', $like);
            })
            ->orderBy('full_name')
            ->limit(8)
            ->get(['id', 'full_name', 'name_ar', 'club', 'position', 'photo_path']);

        return [
            'players' => $rows->map(fn (Player $p): array => [
                'id' => $p->id,
                'full_name' => $p->full_name,
                'name_ar' => $p->name_ar,
                'club' => $p->club,
                'position' => $p->position,
                'photo_url' => $p->photo_path !== null
                    ? Storage::disk('public')->url($p->photo_path)
                    : null,
            ])->all(),
        ];
    }

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive,archived,all'],
            'sort' => ['nullable', 'in:full_name,club,position,created_at'],
            'direction' => ['nullable', 'in:asc,desc'],
        ]);

        $status = $validated['status'] ?? Player::STATUS_ACTIVE;
        $sort = $validated['sort'] ?? 'full_name';
        $direction = $validated['direction'] ?? 'asc';
        $search = $validated['search'] ?? null;

        $players = Player::query()
            ->when(
                $status !== 'all',
                fn (Builder $query): Builder => $query->where('status', $status),
            )
            ->when(
                $search !== null,
                function (Builder $query) use ($search): Builder {
                    $term = '%'.$search.'%';

                    return $query->where(function (Builder $q) use ($term): void {
                        $q->where('full_name', 'ILIKE', $term)
                            ->orWhere('name_ar', 'ILIKE', $term)
                            ->orWhere('club', 'ILIKE', $term)
                            ->orWhere('player_code', 'ILIKE', $term);
                    });
                },
            )
            ->orderBy($sort, $direction)
            ->paginate(self::DEFAULT_PER_PAGE)
            ->withQueryString();

        return Inertia::render('players/index', [
            'players' => [
                'data' => PlayerData::collect($players->items()),
                'meta' => [
                    'current_page' => $players->currentPage(),
                    'last_page' => $players->lastPage(),
                    'per_page' => $players->perPage(),
                    'total' => $players->total(),
                ],
                'links' => [
                    'prev' => $players->previousPageUrl(),
                    'next' => $players->nextPageUrl(),
                ],
            ],
            'filters' => [
                'search' => $search,
                'status' => $status,
                'sort' => $sort,
                'direction' => $direction,
            ],
            'status_counts' => [
                'active' => Player::active()->count(),
                'inactive' => Player::where('status', Player::STATUS_INACTIVE)->count(),
                'archived' => Player::where('status', Player::STATUS_ARCHIVED)->count(),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('players/create');
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Submission>  $submissions
     * @return list<array{id: int, received_at: string, status: string, attachments: list<array{filename: string, mime_type: string, stage: string}>}>
     */
    private function formatInflight($submissions): array
    {
        $result = [];
        foreach ($submissions as $submission) {
            $attachments = [];
            foreach ($submission->attachments as $attachment) {
                $attachments[] = [
                    'filename' => $attachment->original_filename,
                    'mime_type' => $attachment->mime_type,
                    // Rough stage inference — cheap heuristic, no extra query.
                    'stage' => $attachment->is_text_pdf === null
                        ? 'extracting text'
                        : 'classifying',
                ];
            }
            $result[] = [
                'id' => $submission->id,
                'received_at' => $submission->received_at->toIso8601String(),
                'status' => $submission->status,
                'attachments' => $attachments,
            ];
        }

        return $result;
    }

    /**
     * @param  Collection<int, RecordMetric>  $metrics
     * @return array<string, list<array{date: string, value: float, unit: string|null, ref_low: float|null, ref_high: float|null, flag: string|null}>>
     */
    private function groupMetricsByKey($metrics): array
    {
        $result = [];
        foreach ($metrics as $m) {
            $result[$m->metric_key][] = [
                'date' => $m->record_date->toDateString(),
                'value' => (float) $m->metric_value,
                'unit' => $m->unit,
                'ref_low' => $m->ref_low !== null ? (float) $m->ref_low : null,
                'ref_high' => $m->ref_high !== null ? (float) $m->ref_high : null,
                'flag' => $m->flag,
            ];
        }

        return $result;
    }

    public function store(StorePlayerRequest $request): RedirectResponse
    {
        $data = $request->safe()->except('avatar');

        $player = Player::create([
            ...$data,
            'status' => $data['status'] ?? Player::STATUS_ACTIVE,
        ]);

        if ($request->hasFile('avatar')) {
            $path = $request->file('avatar')->store("players/{$player->id}", 'public');
            $player->update(['photo_path' => $path]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Player added.')]);

        return to_route('players.show', $player);
    }

    /**
     * Page size for the player profile's Timeline tab. Initial load
     * + every infinite-scroll page fetches this many records. Matches
     * a comfortable "you can see everything without scrolling much"
     * value at typical viewport sizes.
     */
    private const TIMELINE_PAGE_SIZE = 30;

    public function show(Player $player): Response
    {
        // Timeline — first page (30 records). Lab sample dates can run
        // years before the upload date (e.g. uploading a historical
        // 2024 panel today), so no date floor — we'd hide records the
        // admin uploaded on purpose. Pagination handles volume; see
        // ::timeline() below for subsequent pages.
        $timelinePage = $this->fetchTimelinePage($player, cursorBefore: null);

        // Metrics — full history per canonical key. Trend charts need
        // every point or the line is meaningless. Volume stays small
        // because record_metrics is denormalised + indexed.
        $metrics = $player->metrics()
            ->orderBy('record_date')
            ->get(['record_date', 'metric_key', 'metric_value', 'unit', 'ref_low', 'ref_high', 'flag']);

        $open_alerts = $player->alerts()->open()->count();

        // In-flight + failed uploads — either there's no PlayerRecord yet
        // (still processing) or the chain threw and left the submission
        // stranded. We surface both on the Timeline so the admin sees
        // their upload landed and can retry failures. The show page
        // polls while any `processing` rows exist.
        $inflight = $player->submissions()
            ->whereIn('status', [Submission::STATUS_PROCESSING, Submission::STATUS_FAILED])
            ->with(['attachments:id,submission_id,original_filename,is_text_pdf,mime_type'])
            ->orderByDesc('received_at')
            ->limit(20)
            ->get();

        return Inertia::render('players/show', [
            'player' => PlayerData::fromModel($player),
            'timeline' => $timelinePage['entries'],
            'timeline_next_cursor' => $timelinePage['next_cursor'],
            'inflight' => $this->formatInflight($inflight),
            'metrics' => $this->groupMetricsByKey($metrics),
            'documents' => $this->formatDocuments($player),
            'analyses' => $this->formatNutritionistAnalyses($player),
            'analysis_eligibility' => $this->analysisEligibility($player),
            'alerts' => $this->formatAlerts($player),
            'counts' => [
                'records' => $player->records()->count(),
                'open_alerts' => $open_alerts,
            ],
        ]);
    }

    /**
     * Alerts for the profile's Alerts tab — open ones first (severity
     * critical → warn → info), then acknowledged ones dimmed underneath.
     * 100-row cap matches the global page; one player rarely accrues
     * that many but the cap keeps the payload bounded.
     *
     * @return list<array<string, mixed>>
     */
    private function formatAlerts(Player $player): array
    {
        return $player->alerts()
            ->with('acknowledger:id,name')
            ->orderByRaw('acknowledged_at IS NULL DESC')
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warn' THEN 1 ELSE 2 END")
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (Alert $a): array => [
                'id' => $a->id,
                'severity' => $a->severity,
                'kind' => $a->kind,
                'message' => $a->message,
                'created_at' => $a->created_at?->toIso8601String(),
                'acknowledged_at' => $a->acknowledged_at?->toIso8601String(),
                'acknowledged_by' => $a->acknowledger?->name,
                'player' => [
                    'id' => $player->id,
                    'full_name' => $player->full_name,
                    'name_ar' => $player->name_ar,
                    'photo_url' => null,
                ],
            ])
            ->all();
    }

    /**
     * Nutritionist Assistant runs — newest first, capped at 10. The AI
     * analysis tab renders [0] front-and-centre with the rest behind a
     * "history" disclosure.
     *
     * @return list<array<string, mixed>>
     */
    private function formatNutritionistAnalyses(Player $player): array
    {
        $analyses = $player->nutritionistAnalyses()
            ->with(['generator:id,name'])
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $result = [];
        foreach ($analyses as $a) {
            $result[] = [
                'id' => $a->id,
                'status' => $a->status,
                'model_used' => $a->model_used,
                'generated_at' => $a->generated_at?->toIso8601String(),
                'generated_by' => $a->generator?->name,
                'summary_text' => $a->summary_text,
                'error' => $a->error,
                'payload' => $a->payload,
                'sources' => [
                    'blood_record_id' => $a->source_blood_record_id,
                    'inbody_record_id' => $a->source_inbody_record_id,
                ],
                'created_at' => $a->created_at?->toIso8601String(),
            ];
        }

        return $result;
    }

    /**
     * @return array{has_blood: bool, has_inbody: bool, can_run: bool}
     */
    private function analysisEligibility(Player $player): array
    {
        $hasBlood = $player->records()
            ->whereHas('category', fn ($q) => $q->where('slug', RecordCategory::BLOOD_TEST))
            ->exists();
        $hasInbody = $player->records()
            ->whereHas('category', fn ($q) => $q->where('slug', RecordCategory::INBODY))
            ->exists();

        return [
            'has_blood' => $hasBlood,
            'has_inbody' => $hasInbody,
            'can_run' => $hasBlood && $hasInbody,
        ];
    }

    /**
     * Documents-tab payload: every record on the player paired with its
     * source attachment + reviewer info. Capped at 100 newest entries —
     * if a player accumulates more, paginate the way Timeline already
     * does.
     *
     * @return list<array<string, mixed>>
     */
    private function formatDocuments(Player $player): array
    {
        $records = $player->records()
            ->with([
                'category:id,slug,label_en',
                'primaryAttachment:id,original_filename,mime_type,size_bytes,page_count',
                'reviewer:id,name',
            ])
            ->orderByDesc('record_date')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $result = [];
        foreach ($records as $r) {
            $extracted = $r->extracted ?? [];
            $result[] = [
                'id' => $r->id,
                'record_date' => $r->record_date->toDateString(),
                'category' => [
                    'slug' => $r->category->slug,
                    'label' => $r->category->label_en,
                ],
                'source_lab' => $r->source_lab,
                'summary_text' => $r->summary_text,
                'attachment' => $r->primaryAttachment !== null ? [
                    'id' => $r->primaryAttachment->id,
                    'filename' => $r->primaryAttachment->original_filename,
                    'mime_type' => $r->primaryAttachment->mime_type,
                    'size_bytes' => $r->primaryAttachment->size_bytes,
                    'page_count' => $r->primaryAttachment->page_count,
                    'download_url' => route('attachments.download', $r->primaryAttachment),
                ] : null,
                // Blood-test labs ship typed for table rendering. Other
                // categories return [] here — the raw extracted payload
                // will get its own per-category renderer in follow-ups.
                'labs' => is_array($extracted['labs'] ?? null) ? $extracted['labs'] : [],
                'reviewed' => [
                    'is_reviewed' => (bool) $r->reviewed,
                    'reviewed_at' => $r->reviewed_at?->toIso8601String(),
                    'reviewer_name' => $r->reviewer?->name,
                ],
                'extraction_pending' => (bool) ($extracted['needs_structured_extraction'] ?? false),
                'extraction_failed' => (bool) ($extracted['extraction_failed'] ?? false),
                'extraction_partial' => (bool) ($extracted['extraction_partial'] ?? false),
                'chunks_completed' => is_int($extracted['chunks_completed'] ?? null) ? $extracted['chunks_completed'] : null,
                'chunks_total' => is_int($extracted['chunks_total'] ?? null) ? $extracted['chunks_total'] : null,
                'awaiting_callback' => (bool) ($extracted['awaiting_callback'] ?? false),
            ];
        }

        return $result;
    }

    /**
     * JSON endpoint for infinite-scroll pagination on the player
     * Timeline. Cursor is the (record_date, id) pair from the last
     * record of the previous page; ordering is (record_date DESC, id
     * DESC) so the pair disambiguates ties on the same record_date.
     *
     * @return array{entries: list<array<string, mixed>>, next_cursor: array{date: string, id: int}|null}
     */
    public function timeline(Player $player, Request $request): array
    {
        $cursorId = $request->query('cursor');
        $cursorDate = $request->query('cursor_date');

        return $this->fetchTimelinePage(
            $player,
            cursorBefore: $cursorId !== null && $cursorDate !== null
                ? ['date' => (string) $cursorDate, 'id' => (int) $cursorId]
                : null,
        );
    }

    /**
     * Pull one timeline page newest-first. When `cursorBefore` is set,
     * fetches records strictly older than that (record_date, id) pair.
     *
     * @param  array{date: string, id: int}|null  $cursorBefore
     * @return array{entries: list<array<string, mixed>>, next_cursor: array{date: string, id: int}|null}
     */
    private function fetchTimelinePage(Player $player, ?array $cursorBefore): array
    {
        $pageSize = self::TIMELINE_PAGE_SIZE;

        // Fetch one extra row so we can tell whether there's more
        // beyond this page without a separate count query.
        $records = $player->records()
            ->with(['category:id,slug,label_en', 'primaryAttachment:id,original_filename'])
            ->when($cursorBefore !== null, function ($q) use ($cursorBefore): void {
                // (record_date, id) < (cursor.date, cursor.id) — Postgres
                // tuple compare via the explicit OR form so it works
                // through Eloquent's grammar.
                $q->where(function ($w) use ($cursorBefore): void {
                    $w->where('record_date', '<', $cursorBefore['date'])
                        ->orWhere(function ($e) use ($cursorBefore): void {
                            $e->where('record_date', '=', $cursorBefore['date'])
                                ->where('id', '<', $cursorBefore['id']);
                        });
                });
            })
            ->orderByDesc('record_date')
            ->orderByDesc('id')
            ->limit($pageSize + 1)
            ->get();

        $hasMore = $records->count() > $pageSize;
        $records = $records->take($pageSize);

        $last = $records->last();
        $nextCursor = $hasMore && $last !== null ? [
            'date' => $last->record_date->toDateString(),
            'id' => $last->id,
        ] : null;

        return [
            'entries' => $records->map(fn ($r): array => [
                'id' => $r->id,
                'record_date' => $r->record_date->toDateString(),
                'category' => [
                    'slug' => $r->category->slug,
                    'label' => $r->category->label_en,
                ],
                'summary_text' => $r->summary_text,
                'source_lab' => $r->source_lab,
                'reviewed' => $r->reviewed,
                'attachment_filename' => $r->primaryAttachment?->original_filename,
                'extraction_pending' => (bool) ($r->extracted['needs_structured_extraction'] ?? false),
                'extraction_failed' => (bool) ($r->extracted['extraction_failed'] ?? false),
                'extraction_failure_reason' => $r->extracted['extraction_failure_reason'] ?? null,
                'extraction_partial' => (bool) ($r->extracted['extraction_partial'] ?? false),
                'chunks_completed' => is_int($r->extracted['chunks_completed'] ?? null) ? $r->extracted['chunks_completed'] : null,
                'chunks_total' => is_int($r->extracted['chunks_total'] ?? null) ? $r->extracted['chunks_total'] : null,
                'awaiting_callback' => (bool) ($r->extracted['awaiting_callback'] ?? false),
                // Per-category extras the timeline row renderer expands into a
                // category-specific card. NULL for categories that get the
                // generic one-line summary treatment.
                'details' => $this->buildTimelineDetails($r),
            ])->all(),
            'next_cursor' => $nextCursor,
        ];
    }

    /**
     * Surface a compact, display-ready subset of $record->extracted for
     * categories that want a rich row (today: match_performance — every
     * stat that's worth showing without forcing the admin to click into
     * a detail view). Returns null for categories that get the generic
     * summary-only treatment.
     *
     * Stays inside the controller (vs. a model accessor) because it's a
     * pure transport-layer concern — shape match the TypeScript types in
     * resources/js/components/domain/player-timeline.tsx.
     *
     * @return array<string, mixed>|null
     */
    private function buildTimelineDetails(PlayerRecord $record): ?array
    {
        if ($record->category->slug !== RecordCategory::MATCH_PERFORMANCE) {
            return null;
        }

        $e = $record->extracted ?? [];
        // raw_extracted is the per-player slice from the match-report agent —
        // flat numeric stats sit at the top level of that sub-array.
        $raw = is_array($e['raw_extracted'] ?? null) ? $e['raw_extracted'] : [];

        $int = static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0;
        $intOrNull = static fn (mixed $v): ?int => is_numeric($v) ? (int) $v : null;
        $numOrNull = static fn (mixed $v): ?float => is_numeric($v) ? (float) $v : null;
        $strOrNull = static fn (mixed $v): ?string => is_string($v) && $v !== '' ? $v : null;

        return [
            'match_id' => $intOrNull($e['match_id'] ?? null),
            'opponent' => $strOrNull($e['opponent'] ?? null),
            'competition' => $strOrNull($e['competition'] ?? null),
            'stage' => $strOrNull($e['stage'] ?? null),
            'venue' => $strOrNull($e['venue'] ?? null),
            'home_team_name' => $strOrNull($e['home_team_name'] ?? null),
            'away_team_name' => $strOrNull($e['away_team_name'] ?? null),
            'home_score' => $intOrNull($e['home_score'] ?? null),
            'away_score' => $intOrNull($e['away_score'] ?? null),
            'bahrain_side' => $strOrNull($e['bahrain_side'] ?? null),

            'jersey_number' => $intOrNull($e['jersey_number'] ?? null),
            'match_position' => $strOrNull($e['match_position'] ?? null),
            'appearance' => $strOrNull($e['appearance'] ?? null) ?? 'starter',
            'minutes_played' => $intOrNull($e['minutes_played'] ?? null),
            'rating' => $numOrNull($e['rating'] ?? null),

            'goals' => $int($raw['goals'] ?? 0),
            'assists' => $int($raw['assists'] ?? 0),
            'shots' => $int($raw['shots'] ?? 0),
            'shots_on_target' => $int($raw['shots_on_target'] ?? 0),
            'key_passes' => $int($raw['key_passes'] ?? 0),
            'passes_total' => $int($raw['passes_total'] ?? 0),
            'passes_succeeded' => $int($raw['passes_succeeded'] ?? 0),
            'pass_accuracy_pct' => $numOrNull($raw['pass_accuracy_pct'] ?? null),
            'take_ons_attempted' => $int($raw['take_ons_attempted'] ?? 0),
            'take_ons_succeeded' => $int($raw['take_ons_succeeded'] ?? 0),
            'crosses_attempted' => $int($raw['crosses_attempted'] ?? 0),
            'crosses_succeeded' => $int($raw['crosses_succeeded'] ?? 0),

            'tackles_attempted' => $int($raw['tackles_attempted'] ?? 0),
            'tackles_succeeded' => $int($raw['tackles_succeeded'] ?? 0),
            'aerial_duels_total' => $int($raw['aerial_duels_total'] ?? 0),
            'aerial_duels_won' => $int($raw['aerial_duels_won'] ?? 0),
            'ground_duels_total' => $int($raw['ground_duels_total'] ?? 0),
            'ground_duels_won' => $int($raw['ground_duels_won'] ?? 0),
            'interceptions' => $int($raw['interceptions'] ?? 0),
            'clearances' => $int($raw['clearances'] ?? 0),
            'recoveries' => $int($raw['recoveries'] ?? 0),
            'blocks' => $int($raw['blocks'] ?? 0),

            'fouls_committed' => $int($raw['fouls_committed'] ?? 0),
            'fouls_won' => $int($raw['fouls_won'] ?? 0),
            'yellow_cards' => $int($raw['yellow_cards'] ?? 0),
            'red_cards' => $int($raw['red_cards'] ?? 0),

            // Goalkeeper-only — null for outfield (helper renders them as a
            // dedicated section only when at least one is non-null).
            'goals_conceded' => $intOrNull($raw['goals_conceded'] ?? null),
            'catches' => $intOrNull($raw['catches'] ?? null),
            'parries' => $intOrNull($raw['parries'] ?? null),
        ];
    }

    public function edit(Player $player): Response
    {
        return Inertia::render('players/edit', [
            'player' => PlayerData::fromModel($player),
        ]);
    }

    public function update(UpdatePlayerRequest $request, Player $player): RedirectResponse
    {
        $data = $request->safe()->except(['avatar', 'remove_avatar']);

        $player->fill($data);

        if ($request->boolean('remove_avatar') && $player->photo_path !== null) {
            Storage::disk('public')->delete($player->photo_path);
            $player->photo_path = null;
        }

        if ($request->hasFile('avatar')) {
            if ($player->photo_path !== null) {
                Storage::disk('public')->delete($player->photo_path);
            }
            $player->photo_path = $request->file('avatar')->store("players/{$player->id}", 'public');
        }

        $player->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Player updated.')]);

        return to_route('players.show', $player);
    }

    public function archive(Player $player): RedirectResponse
    {
        $player->update(['status' => Player::STATUS_ARCHIVED]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Player archived.')]);

        return back();
    }

    public function restore(Player $player): RedirectResponse
    {
        $player->update(['status' => Player::STATUS_ACTIVE]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Player restored.')]);

        return back();
    }
}
