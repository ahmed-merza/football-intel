<?php

declare(strict_types=1);

namespace App\Services\Match;

use App\Models\MatchPerformance;
use App\Models\MatchReport;
use App\Models\Player;
use App\Models\PlayerRecord;
use App\Models\RecordCategory;
use App\Services\Medical\RecordMetricFanner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Single source of truth for "we have an extracted match report payload —
 * write it into the relational layer". Two call sites end up here:
 *
 *   - The sync success path inside {@see \App\Jobs\ExtractMatchReportJob}
 *     calls {@see applyExtraction()} as soon as the LLM responds.
 *   - The async callback path inside the n8n webhook controller calls
 *     {@see applyExtraction()} when n8n finally POSTs the result after
 *     the sync HTTP path 504'd.
 *
 * After the extraction is on the {@see MatchReport} row, the admin reviews
 * + resolves players in the preview UI; the controller then calls
 * {@see applyResolution()} which commits per-player rows + PlayerRecords
 * + record_metrics inside a single transaction. Idempotent: a re-apply
 * (admin edited resolutions, hit "save" again) wipes the existing
 * MatchPerformance + PlayerRecord rows for the report first.
 */
class MatchReportApplier
{
    public function __construct(private RecordMetricFanner $fanner) {}

    /**
     * Step 1: store the raw extractor payload + derive match meta. No
     * per-player rows yet — those land at {@see applyResolution()} once
     * the admin has resolved players. Idempotent: re-extracting overwrites.
     *
     * @param  array<string, mixed>  $payload  The MatchReportExtractor output.
     */
    public function applyExtraction(MatchReport $report, array $payload): void
    {
        $meta = $this->matchMeta($payload);

        $report->update([
            'competition' => $meta['competition'] ?? $report->competition,
            'stage' => $meta['stage'] ?? $report->stage,
            'match_date' => $meta['match_date'] ?? $report->match_date,
            'kickoff_time' => $meta['kickoff_time'] ?? $report->kickoff_time,
            'venue' => $meta['venue'] ?? $report->venue,
            'home_team_name' => $meta['home_team_name'] ?? $report->home_team_name,
            'away_team_name' => $meta['away_team_name'] ?? $report->away_team_name,
            'home_score' => $meta['home_score'] ?? $report->home_score,
            'away_score' => $meta['away_score'] ?? $report->away_score,
            'bahrain_side' => $meta['bahrain_side'],
            'opponent_name' => $meta['opponent_name'],
            'raw_extracted' => $payload,
            'extracted_at' => Carbon::now(),
            'status' => MatchReport::STATUS_EXTRACTED,
            'extraction_error' => null,
        ]);
    }

    /**
     * Step 2: admin has resolved every Bahrain row (existing player /
     * create new / skip). Apply atomically — match_performances +
     * player_records (for resolved Bahrain players) + record_metrics
     * fan-out, all inside a single DB transaction so a partial failure
     * rolls back and the preview screen reloads unchanged.
     *
     * @param  array<int, array{type: string, player_id?: int|null, data?: array<string, mixed>}>  $resolutions
     *                                  Keyed by the performance's 0-based index into
     *                                  $report->raw_extracted['performances']. Missing keys are treated as
     *                                  'skip' (defensive: opposition rows + admin-skipped rows). Allowed
     *                                  types: 'existing' (player_id required), 'new' (data required),
     *                                  'skip' (no fields).
     */
    public function applyResolution(MatchReport $report, array $resolutions): void
    {
        if (! is_array($report->raw_extracted) || ! isset($report->raw_extracted['performances'])) {
            throw new InvalidArgumentException(
                "MatchReport #{$report->id} has no extracted performances to apply.",
            );
        }

        /** @var array<int, array<string, mixed>> $performances */
        $performances = $report->raw_extracted['performances'];

        DB::transaction(function () use ($report, $performances, $resolutions): void {
            $this->wipeExistingApply($report);

            $matchCategory = RecordCategory::where('slug', RecordCategory::MATCH_PERFORMANCE)
                ->firstOrFail();

            foreach ($performances as $index => $perf) {
                if (! is_array($perf)) {
                    continue;
                }

                $teamSide = $this->resolveTeamSide($report, (string) ($perf['team_side'] ?? ''));
                $resolution = $resolutions[$index] ?? ['type' => 'skip'];

                $playerId = $this->resolvePlayerId($teamSide, $resolution);

                $performanceRow = $this->writePerformance(
                    $report,
                    $teamSide,
                    $playerId,
                    $perf,
                );

                if ($teamSide === MatchPerformance::TEAM_SIDE_BAHRAIN && $playerId !== null) {
                    $playerRecord = $this->writePlayerRecord(
                        $report,
                        $matchCategory->id,
                        $playerId,
                        $performanceRow,
                        $perf,
                    );

                    $performanceRow->update(['player_record_id' => $playerRecord->id]);

                    $this->fanner->fan($playerRecord->fresh(['category']));
                }
            }

            $report->update([
                'status' => MatchReport::STATUS_APPLIED,
                'applied_at' => Carbon::now(),
            ]);
        });
    }

    /**
     * Re-applying a match (admin edited a resolution, hit save again) must
     * not double-write. Drop the prior performance + player_record fan-out
     * for this report, then we re-derive from scratch.
     */
    private function wipeExistingApply(MatchReport $report): void
    {
        $priorRecordIds = MatchPerformance::query()
            ->where('match_report_id', $report->id)
            ->whereNotNull('player_record_id')
            ->pluck('player_record_id');

        // Performances cascade to record_metrics via PlayerRecord deletion.
        // Hard delete (not soft) — the report itself is auditable, the
        // derived rows aren't worth preserving across an admin re-apply.
        if ($priorRecordIds->isNotEmpty()) {
            PlayerRecord::query()->whereIn('id', $priorRecordIds)->forceDelete();
        }

        MatchPerformance::where('match_report_id', $report->id)->delete();
    }

    /**
     * Map the extractor's 'home'/'away' onto our domain's 'bahrain'/'opponent'
     * using the report's pre-computed bahrain_side. When neither side is
     * Bahrain (shouldn't happen in V1 but the column allows it), every row
     * lands as 'opponent' — applier still writes the performances for
     * team-level stats but never creates PlayerRecords.
     */
    private function resolveTeamSide(MatchReport $report, string $extractorSide): string
    {
        return $extractorSide === $report->bahrain_side
            ? MatchPerformance::TEAM_SIDE_BAHRAIN
            : MatchPerformance::TEAM_SIDE_OPPONENT;
    }

    /**
     * @param  array{type: string, player_id?: int|null, data?: array<string, mixed>}  $resolution
     */
    private function resolvePlayerId(string $teamSide, array $resolution): ?int
    {
        // Opposition rows are never linked to a player_id by design — even
        // if the admin somehow submitted a resolution for one.
        if ($teamSide !== MatchPerformance::TEAM_SIDE_BAHRAIN) {
            return null;
        }

        return match ($resolution['type'] ?? 'skip') {
            'existing' => is_int($resolution['player_id'] ?? null) ? $resolution['player_id'] : null,

            'new' => $this->createPlayerFromResolution(
                is_array($resolution['data'] ?? null) ? $resolution['data'] : [],
            )->id,

            default => null, // 'skip' or unknown
        };
    }

    /**
     * Inline player creation from the resolution UI — the form's validation
     * runs in the controller (FormRequest), so by the time we get here the
     * shape is sane. We still defensively coerce types because the data
     * round-trips through JSON.
     *
     * @param  array<string, mixed>  $data
     */
    private function createPlayerFromResolution(array $data): Player
    {
        $fullName = is_string($data['full_name'] ?? null) ? trim($data['full_name']) : '';
        if ($fullName === '') {
            throw new InvalidArgumentException("Resolution type='new' requires non-empty full_name.");
        }

        return Player::create([
            'full_name' => $fullName,
            'name_ar' => is_string($data['name_ar'] ?? null) ? $data['name_ar'] : null,
            'position' => is_string($data['position'] ?? null) ? $data['position'] : null,
            'nationality' => is_string($data['nationality'] ?? null) ? $data['nationality'] : null,
            'status' => Player::STATUS_ACTIVE,
        ]);
    }

    /**
     * @param  array<string, mixed>  $perf
     */
    private function writePerformance(MatchReport $report, string $teamSide, ?int $playerId, array $perf): MatchPerformance
    {
        return MatchPerformance::create([
            'match_report_id' => $report->id,
            'team_side' => $teamSide,
            'player_id' => $playerId,
            'reported_name' => $this->str($perf['reported_name'] ?? null),
            'jersey_number' => $this->int($perf['jersey_number'] ?? null),
            'match_position' => $this->str($perf['match_position'] ?? null),
            'appearance' => $this->str($perf['appearance'] ?? null) ?? MatchPerformance::APPEARANCE_STARTER,
            'minute_on' => $this->int($perf['minute_on'] ?? null),
            'minute_off' => $this->int($perf['minute_off'] ?? null),
            'minutes_played' => $this->int($perf['minutes_played'] ?? null),
            'rating' => $this->num($perf['rating'] ?? null),

            'goals' => $this->intOr0($perf['goals'] ?? null),
            'assists' => $this->intOr0($perf['assists'] ?? null),
            'shots' => $this->intOr0($perf['shots'] ?? null),
            'shots_on_target' => $this->intOr0($perf['shots_on_target'] ?? null),
            'shots_blocked' => $this->intOr0($perf['shots_blocked'] ?? null),
            'shots_missed' => $this->intOr0($perf['shots_missed'] ?? null),
            'shots_inside_pa' => $this->intOr0($perf['shots_inside_pa'] ?? null),
            'shots_outside_pa' => $this->intOr0($perf['shots_outside_pa'] ?? null),
            'offsides' => $this->intOr0($perf['offsides'] ?? null),
            'freekicks_taken' => $this->intOr0($perf['freekicks_taken'] ?? null),
            'corners_taken' => $this->intOr0($perf['corners_taken'] ?? null),
            'throw_ins' => $this->intOr0($perf['throw_ins'] ?? null),
            'take_ons_attempted' => $this->intOr0($perf['take_ons_attempted'] ?? null),
            'take_ons_succeeded' => $this->intOr0($perf['take_ons_succeeded'] ?? null),

            'passes_total' => $this->intOr0($perf['passes_total'] ?? null),
            'passes_succeeded' => $this->intOr0($perf['passes_succeeded'] ?? null),
            'pass_accuracy_pct' => $this->num($perf['pass_accuracy_pct'] ?? null),
            'key_passes' => $this->intOr0($perf['key_passes'] ?? null),
            'crosses_attempted' => $this->intOr0($perf['crosses_attempted'] ?? null),
            'crosses_succeeded' => $this->intOr0($perf['crosses_succeeded'] ?? null),
            'controls_under_pressure' => $this->intOr0($perf['controls_under_pressure'] ?? null),

            'tackles_attempted' => $this->intOr0($perf['tackles_attempted'] ?? null),
            'tackles_succeeded' => $this->intOr0($perf['tackles_succeeded'] ?? null),
            'aerial_duels_total' => $this->intOr0($perf['aerial_duels_total'] ?? null),
            'aerial_duels_won' => $this->intOr0($perf['aerial_duels_won'] ?? null),
            'ground_duels_total' => $this->intOr0($perf['ground_duels_total'] ?? null),
            'ground_duels_won' => $this->intOr0($perf['ground_duels_won'] ?? null),
            'interceptions' => $this->intOr0($perf['interceptions'] ?? null),
            'clearances' => $this->intOr0($perf['clearances'] ?? null),
            'interventions' => $this->intOr0($perf['interventions'] ?? null),
            'recoveries' => $this->intOr0($perf['recoveries'] ?? null),
            'blocks' => $this->intOr0($perf['blocks'] ?? null),
            'mistakes' => $this->intOr0($perf['mistakes'] ?? null),

            'fouls_committed' => $this->intOr0($perf['fouls_committed'] ?? null),
            'fouls_won' => $this->intOr0($perf['fouls_won'] ?? null),
            'yellow_cards' => $this->intOr0($perf['yellow_cards'] ?? null),
            'red_cards' => $this->intOr0($perf['red_cards'] ?? null),

            'goals_conceded' => $this->int($perf['goals_conceded'] ?? null),
            'catches' => $this->int($perf['catches'] ?? null),
            'parries' => $this->int($perf['parries'] ?? null),
            'goal_kicks_attempted' => $this->int($perf['goal_kicks_attempted'] ?? null),
            'goal_kicks_succeeded' => $this->int($perf['goal_kicks_succeeded'] ?? null),
            'aerial_clearances_attempted' => $this->int($perf['aerial_clearances_attempted'] ?? null),
            'aerial_clearances_succeeded' => $this->int($perf['aerial_clearances_succeeded'] ?? null),

            // Keep the raw extractor row for diffing + admin re-views.
            'raw_extracted' => $perf,
        ]);
    }

    /**
     * Mirror the medical-extraction pattern: write a PlayerRecord with a
     * `metrics` array shaped exactly like what BloodTestExtractor.labs /
     * InBodyExtractor.metrics produce, so the existing RecordMetricFanner
     * picks it up by following one more entry in its SOURCE_KEY_BY_CATEGORY
     * map.
     *
     * `extracted` also carries the match context (date, opponent, score)
     * so the Nutritionist Assistant, when it eventually reads these rows,
     * has enough situational data to reason "this was an away match against
     * Saudi U19, player was on for 90 minutes, rating 6.4…"
     *
     * @param  array<string, mixed>  $perf
     */
    private function writePlayerRecord(
        MatchReport $report,
        int $categoryId,
        int $playerId,
        MatchPerformance $performance,
        array $perf,
    ): PlayerRecord {
        $extracted = [
            // Match context — the cross-domain agent needs this to interpret.
            'match_id' => $report->id,
            'match_date' => $report->match_date?->toDateString(),
            'competition' => $report->competition,
            'stage' => $report->stage,
            'opponent' => $report->opponent(),
            'venue' => $report->venue,
            'home_team_name' => $report->home_team_name,
            'away_team_name' => $report->away_team_name,
            'home_score' => $report->home_score,
            'away_score' => $report->away_score,
            'bahrain_side' => $report->bahrain_side,

            // Player's role this match.
            'jersey_number' => $performance->jersey_number,
            'match_position' => $performance->match_position,
            'appearance' => $performance->appearance,
            'minute_on' => $performance->minute_on,
            'minute_off' => $performance->minute_off,
            'minutes_played' => $performance->minutes_played,
            'rating' => $performance->rating !== null ? (float) $performance->rating : null,

            // Fan-out source — shape mirrors BloodTestExtractor.labs and
            // InBodyExtractor.metrics so RecordMetricFanner reads it via
            // its existing entry-loop without per-category branching.
            'metrics' => $this->fannableMetrics($performance),

            // Full per-player extractor slice (pass breakdowns etc.) preserved
            // for the Nutritionist Assistant + future reports.
            'raw_extracted' => $perf,
        ];

        return PlayerRecord::create([
            'player_id' => $playerId,
            'category_id' => $categoryId,
            'record_date' => $report->match_date,
            'submission_id' => $report->submission_id,
            'primary_attachment_id' => $report->attachment_id,
            'extracted' => $extracted,
            'source_lab' => $report->competition ?: $report->venue,
            'summary_text' => $this->summarise($performance, $report),
        ]);
    }

    /**
     * Translate the flat MatchPerformance columns into the shape
     * RecordMetricFanner expects ({key, value, unit, ref_low, ref_high, flag}).
     * Keys live in the registry; one per chartable headline metric.
     *
     * Computed ratios (tackles_won_pct, duels_won_pct) are derived here
     * rather than stored as columns because they're trivially recomputable
     * and would otherwise need a maintenance column on every report import.
     *
     * @return list<array{key: string, name: string, value: float, unit: string|null, ref_low: null, ref_high: null, flag: string}>
     */
    private function fannableMetrics(MatchPerformance $p): array
    {
        $rows = [];

        $push = static function (string $key, string $name, ?float $value, ?string $unit) use (&$rows): void {
            if ($value === null) {
                return;
            }
            $rows[] = [
                'key' => $key,
                'name' => $name,
                'value' => $value,
                'unit' => $unit,
                'ref_low' => null,
                'ref_high' => null,
                'flag' => 'normal',  // match data has no clinical "low/high" — fanner needs the field present
            ];
        };

        $push('match_rating', 'Rating', $p->rating !== null ? (float) $p->rating : null, null);
        $push('match_minutes_played', 'Minutes played', $p->minutes_played !== null ? (float) $p->minutes_played : null, 'min');
        $push('match_goals', 'Goals', (float) $p->goals, null);
        $push('match_assists', 'Assists', (float) $p->assists, null);
        $push('match_shots', 'Shots', (float) $p->shots, null);
        $push('match_shots_on_target', 'Shots on target', (float) $p->shots_on_target, null);
        $push('match_passes_total', 'Passes', (float) $p->passes_total, null);
        $push('match_passes_succeeded', 'Passes succeeded', (float) $p->passes_succeeded, null);
        $push('match_pass_accuracy_pct', 'Pass accuracy', $p->pass_accuracy_pct !== null ? (float) $p->pass_accuracy_pct : null, '%');
        $push('match_key_passes', 'Key passes', (float) $p->key_passes, null);
        $push('match_crosses_succeeded', 'Crosses succeeded', (float) $p->crosses_succeeded, null);
        $push('match_take_ons_succeeded', 'Take-ons succeeded', (float) $p->take_ons_succeeded, null);

        // Derived ratios — only emit when the denominator is non-zero so we
        // don't fan a misleading 0% for players who never tried a tackle.
        $push('match_tackles_won_pct', 'Tackles won %', $this->pct($p->tackles_succeeded, $p->tackles_attempted), '%');
        $push('match_aerial_duels_won_pct', 'Aerial duels won %', $this->pct($p->aerial_duels_won, $p->aerial_duels_total), '%');
        $push('match_ground_duels_won_pct', 'Ground duels won %', $this->pct($p->ground_duels_won, $p->ground_duels_total), '%');

        $push('match_interceptions', 'Interceptions', (float) $p->interceptions, null);
        $push('match_clearances', 'Clearances', (float) $p->clearances, null);
        $push('match_recoveries', 'Recoveries', (float) $p->recoveries, null);
        $push('match_blocks', 'Blocks', (float) $p->blocks, null);
        $push('match_fouls_committed', 'Fouls committed', (float) $p->fouls_committed, null);
        $push('match_fouls_won', 'Fouls won', (float) $p->fouls_won, null);
        $push('match_yellow_cards', 'Yellow cards', (float) $p->yellow_cards, null);
        $push('match_red_cards', 'Red cards', (float) $p->red_cards, null);

        // GK-only — null for outfield so the helper skips them automatically.
        $push('match_goals_conceded', 'Goals conceded', $p->goals_conceded !== null ? (float) $p->goals_conceded : null, null);
        $push('match_parries', 'Parries', $p->parries !== null ? (float) $p->parries : null, null);
        $push('match_catches', 'Catches', $p->catches !== null ? (float) $p->catches : null, null);

        return $rows;
    }

    /**
     * One-line summary that lands on player_records.summary_text and
     * surfaces in the timeline list. Mirrors the per-category summaries
     * in {@see \App\Services\Medical\ExtractionApplier} for visual parity.
     */
    private function summarise(MatchPerformance $p, MatchReport $report): string
    {
        $opponent = $report->opponent() ?? 'opponent';
        $parts = [];

        if ($p->rating !== null) {
            $parts[] = "rating {$p->rating}";
        }
        if ($p->minutes_played !== null) {
            $parts[] = "{$p->minutes_played}'";
        }
        if ($p->goals > 0) {
            $parts[] = "{$p->goals} goal".($p->goals > 1 ? 's' : '');
        }
        if ($p->assists > 0) {
            $parts[] = "{$p->assists} assist".($p->assists > 1 ? 's' : '');
        }
        if ($p->yellow_cards > 0) {
            $parts[] = 'yellow';
        }
        if ($p->red_cards > 0) {
            $parts[] = 'red';
        }

        $tail = $parts === [] ? 'unused' : implode(', ', $parts);

        return "vs {$opponent} — {$tail}.";
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @return array{
     *     competition: string|null, stage: string|null, match_date: string|null,
     *     kickoff_time: string|null, venue: string|null,
     *     home_team_name: string|null, away_team_name: string|null,
     *     home_score: int|null, away_score: int|null,
     *     bahrain_side: string|null, opponent_name: string|null,
     * }
     */
    private function matchMeta(array $payload): array
    {
        $home = $this->str($payload['home_team_name'] ?? null);
        $away = $this->str($payload['away_team_name'] ?? null);

        $bahrainSide = null;
        $opponent = null;
        if ($home !== null && stripos($home, 'bahrain') !== false) {
            $bahrainSide = MatchReport::BAHRAIN_SIDE_HOME;
            $opponent = $away;
        } elseif ($away !== null && stripos($away, 'bahrain') !== false) {
            $bahrainSide = MatchReport::BAHRAIN_SIDE_AWAY;
            $opponent = $home;
        }

        return [
            'competition' => $this->str($payload['competition'] ?? null),
            'stage' => $this->str($payload['stage'] ?? null),
            'match_date' => $this->parseDate($payload['match_date'] ?? null),
            'kickoff_time' => $this->str($payload['kickoff_time'] ?? null),
            'venue' => $this->str($payload['venue'] ?? null),
            'home_team_name' => $home,
            'away_team_name' => $away,
            'home_score' => $this->int($payload['home_score'] ?? null),
            'away_score' => $this->int($payload['away_score'] ?? null),
            'bahrain_side' => $bahrainSide,
            'opponent_name' => $opponent,
        ];
    }

    private function pct(int $succeeded, int $total): ?float
    {
        if ($total <= 0) {
            return null;
        }

        return round(($succeeded / $total) * 100, 2);
    }

    private function str(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    private function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function intOr0(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function num(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function parseDate(mixed $raw): ?string
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        try {
            return Carbon::parse($raw)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
