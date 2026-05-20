<?php

declare(strict_types=1);

namespace App\Services\Match;

use App\Models\MatchPerformance;
use App\Models\Player;
use Illuminate\Support\Carbon;

/**
 * Suggests existing-player matches for the Bahrain rows of a freshly
 * extracted match report, so the resolution UI can pre-fill the dropdown
 * and the admin only has to click "confirm" for the easy cases.
 *
 * Two signals:
 *
 *  1. JERSEY HISTORY (highest confidence). Anonymised reports use
 *     placeholders like "Player 5", "Player 11" — name matching is useless
 *     there. We look up prior {@see MatchPerformance} rows on the Bahrain
 *     side with the same jersey number and a resolved player_id; if one
 *     exists in the recent past, the admin already named that jersey at
 *     least once and we propagate the answer.
 *
 *  2. NAME FUZZY (medium confidence). When a name is present, score it
 *     against every active player's `full_name` using PHP-native string
 *     similarity. Works without pg_trgm — which matters since production
 *     is MySQL and the squad size makes a O(n × m) PHP loop trivial
 *     (n = 30 players, m = ~30 performances per match).
 *
 * Position is a tie-breaker, not a primary signal: match positions
 * (CB/CDM/LW) are finer-grained than the players table's broad family
 * (DF/MF/FW), so equality is rarely useful but a same-family bonus
 * disambiguates between two near-name matches.
 *
 * Returns a ranked list per performance row — empty list if nothing
 * crosses the confidence floor and the admin must pick manually.
 */
class PlayerResolver
{
    private const JERSEY_HISTORY_WINDOW_MONTHS = 12;

    private const NAME_SIMILARITY_FLOOR = 60.0;

    private const NAME_SIMILARITY_AUTO_THRESHOLD = 90.0;

    /**
     * Top N suggestions to surface in the dropdown. Higher numbers
     * become noise.
     */
    private const MAX_SUGGESTIONS = 3;

    /**
     * @param  array{
     *     reported_name?: string|null,
     *     jersey_number?: int|null,
     *     match_position?: string|null,
     *     team_side?: string,
     * } $performance Raw row from the extractor.
     *
     * @return list<array{
     *     player_id: int,
     *     player_name: string,
     *     confidence: 'high'|'medium'|'low',
     *     method: 'jersey_history'|'name_fuzzy',
     *     score: float,
     *     auto_apply: bool,
     * }>
     */
    public function suggest(array $performance): array
    {
        // Opposition rows are never resolved — empty list signals "skip".
        if (($performance['team_side'] ?? null) !== MatchPerformance::TEAM_SIDE_BAHRAIN) {
            return [];
        }

        $suggestions = [];

        // 1. Jersey history. If the admin previously named jersey N on the
        // Bahrain side, propagate that. High confidence even for anonymised
        // "Player N" rows because the jersey is reliable.
        $jersey = $performance['jersey_number'] ?? null;
        if (is_int($jersey)) {
            $historical = $this->jerseyHistoryCandidate($jersey);
            if ($historical !== null) {
                $suggestions[] = [
                    'player_id' => $historical->id,
                    'player_name' => $historical->full_name,
                    'confidence' => 'high',
                    'method' => 'jersey_history',
                    'score' => 100.0,
                    'auto_apply' => true,
                ];
            }
        }

        // 2. Name fuzzy. Only when we have a real name (anonymised "Player N"
        // rows skip this — the placeholder shouldn't fuzzy-match to anyone).
        $name = $performance['reported_name'] ?? null;
        if (is_string($name) && trim($name) !== '' && ! $this->looksAnonymised($name)) {
            $position = $performance['match_position'] ?? null;
            foreach ($this->nameFuzzyCandidates($name, is_string($position) ? $position : null) as $candidate) {
                // Don't surface the same player twice when jersey history
                // already nailed it.
                if ($this->alreadySuggested($suggestions, $candidate['player_id'])) {
                    continue;
                }
                $suggestions[] = $candidate;
                if (count($suggestions) >= self::MAX_SUGGESTIONS) {
                    break;
                }
            }
        }

        return array_slice($suggestions, 0, self::MAX_SUGGESTIONS);
    }

    /**
     * "Player 5", "Player 11", "Unknown 12", "unnamed" — bypass name fuzzy.
     * The pattern is wide; false positives are fine because they just push
     * the admin onto manual selection.
     */
    private function looksAnonymised(string $name): bool
    {
        return (bool) preg_match('/^\s*(player|unknown|unnamed|trial(ist)?)\s*\d*\s*$/i', $name);
    }

    private function jerseyHistoryCandidate(int $jersey): ?Player
    {
        $cutoff = Carbon::now()->subMonths(self::JERSEY_HISTORY_WINDOW_MONTHS);

        /** @var MatchPerformance|null $row */
        $row = MatchPerformance::query()
            ->where('team_side', MatchPerformance::TEAM_SIDE_BAHRAIN)
            ->where('jersey_number', $jersey)
            ->whereNotNull('player_id')
            ->where('created_at', '>=', $cutoff)
            ->latest('id')
            ->first();

        return $row?->player;
    }

    /**
     * @return list<array{
     *     player_id: int,
     *     player_name: string,
     *     confidence: 'high'|'medium'|'low',
     *     method: 'name_fuzzy',
     *     score: float,
     *     auto_apply: bool,
     * }>
     */
    private function nameFuzzyCandidates(string $reportedName, ?string $matchPosition): array
    {
        // Small enough to load — Bahrain U20 / senior squads sit at ~30 active
        // players. similar_text() is O(n*m) on string length, ignorable here.
        $candidates = [];
        $players = Player::query()
            ->where('status', Player::STATUS_ACTIVE)
            ->get(['id', 'full_name', 'name_ar', 'position']);

        $needle = $this->normalise($reportedName);
        $matchFamily = $this->positionFamily($matchPosition);

        foreach ($players as $player) {
            $score = $this->bestNameScore($needle, $player->full_name, $player->name_ar);

            if ($matchFamily !== null && $this->positionFamily($player->position) === $matchFamily) {
                $score += 5.0;   // tie-breaker bump for same position family
            }

            if ($score < self::NAME_SIMILARITY_FLOOR) {
                continue;
            }

            $candidates[] = [
                'player_id' => $player->id,
                'player_name' => $player->full_name,
                'confidence' => $score >= self::NAME_SIMILARITY_AUTO_THRESHOLD ? 'high' : 'medium',
                'method' => 'name_fuzzy',
                'score' => $score,
                'auto_apply' => $score >= self::NAME_SIMILARITY_AUTO_THRESHOLD,
            ];
        }

        usort($candidates, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $candidates;
    }

    private function bestNameScore(string $needle, string $latin, ?string $arabic): float
    {
        // similar_text returns the percentage on the by-ref third argument.
        similar_text($needle, $this->normalise($latin), $latinPct);

        $arabicPct = 0.0;
        if (is_string($arabic) && $arabic !== '') {
            // Arabic-side comparison only fires when the reported_name also
            // happens to be Arabic. Latin reports won't score high here
            // — that's fine, we take the max.
            similar_text($needle, $this->normalise($arabic), $arabicPct);
        }

        return max($latinPct, $arabicPct);
    }

    /**
     * Strip noise that breaks similarity scoring on otherwise-identical names:
     * extra whitespace, punctuation, common honourifics. Case-insensitive.
     */
    private function normalise(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/[\.,\-_\/]+/', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * Players table uses broad families (GK/DF/MF/FW) or specific codes; match
     * reports use specific codes (CB/CDM/LW). Map both onto GK/DF/MF/FW so we
     * can compare. Unknowns return null so non-equal positions aren't penalised.
     */
    private function positionFamily(?string $position): ?string
    {
        if ($position === null || $position === '') {
            return null;
        }
        $p = strtoupper(trim($position));

        return match (true) {
            $p === 'GK' => 'GK',
            in_array($p, ['DF', 'CB', 'LB', 'RB', 'LWB', 'RWB'], true) => 'DF',
            in_array($p, ['MF', 'CM', 'CDM', 'CAM', 'DM', 'AM'], true) => 'MF',
            in_array($p, ['FW', 'CF', 'ST', 'LW', 'RW', 'LF', 'RF'], true) => 'FW',
            default => null,
        };
    }

    /**
     * @param  list<array{player_id: int}>  $suggestions
     */
    private function alreadySuggested(array $suggestions, int $playerId): bool
    {
        foreach ($suggestions as $s) {
            if ($s['player_id'] === $playerId) {
                return true;
            }
        }

        return false;
    }
}
