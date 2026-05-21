<?php

declare(strict_types=1);

namespace App\Services\Match;

use App\Ai\Agents\MatchReportExtractor;

/**
 * Trims an AGCFF-style match-report PDF's extracted text down to just the
 * sections the {@see MatchReportExtractor} actually uses:
 *
 *   1. The header (match meta + scorers — first page through the Table of
 *      Contents marker)
 *   2. The Team Data + Player Stats tables (last 4-5 pages of the report)
 *
 * Everything in between — average-position diagrams, 15-min interval splits,
 * shot-by-shot detail, pass-network diagrams, cross/duel/goalkeeper detail —
 * is dropped from the prompt. None of it lands in our DB today (no schema
 * slots for event-level data), so sending it to Claude just inflates input
 * cost and slows the run. The original PDF is still on disk on the
 * Attachment row, so future extractors can read those sections directly
 * via their own jobs without re-uploading.
 *
 * Trimming a typical 50-page report cuts the input from ~50K tokens down
 * to ~5K. On sonnet that's the difference between a 10-minute run hitting
 * the CLI's 32K output cap and a 1-2 minute clean completion.
 *
 * Defensive fallback: if the well-known markers aren't found (format
 * drifted, different provider's report), return the original text
 * unchanged. Better to send too much than too little.
 */
class MatchReportTextPreprocessor
{
    /**
     * Header section ends where the Table of Contents starts. Anything
     * before is the cover page + scorer/score summary that the extractor's
     * `competition`, `match_date`, `home_team_name`, `home_score`, etc.
     * fields are derived from.
     */
    private const HEADER_END_MARKER = 'Table of Contents';

    /**
     * Tail section starts at the LAST "Team Data" occurrence. The first
     * occurrence is in the Table of Contents; the actual section heading
     * sits at the end of the document, immediately before Player Stats.
     */
    private const TAIL_START_MARKER = 'Team Data';

    /**
     * Tail section ends where the Event Definition glossary starts.
     * Everything after is just terminology — no data to extract.
     */
    private const TAIL_END_MARKER = 'Event Definition';

    public function trim(string $rawText): string
    {
        // First-occurrence for the header end marker (Table of Contents
        // appears only once — at the top).
        $headerEnd = mb_stripos($rawText, self::HEADER_END_MARKER);

        // Last-occurrence for the section markers: each of "Team Data" and
        // "Event Definition" appears once in the Table of Contents at the
        // top AND once as the actual section heading near the bottom. We
        // want the section headings, which is the last one in both cases.
        $tailStart = mb_strripos($rawText, self::TAIL_START_MARKER);
        $tailEnd = mb_strripos($rawText, self::TAIL_END_MARKER);

        // Either marker missing → format probably isn't AGCFF (or has
        // drifted). Send the full text and let the model handle it.
        if ($headerEnd === false || $tailStart === false) {
            return $rawText;
        }

        // Sanity check the ordering: tailStart should sit AFTER headerEnd.
        // If a report is so small the markers overlap, just return as-is.
        if ($tailStart <= $headerEnd) {
            return $rawText;
        }

        $header = mb_substr($rawText, 0, $headerEnd);
        $tail = $tailEnd !== false && $tailEnd > $tailStart
            ? mb_substr($rawText, $tailStart, $tailEnd - $tailStart)
            : mb_substr($rawText, $tailStart);

        return rtrim($header)
            ."\n\n--- (intermediate sections omitted by preprocessor) ---\n\n"
            .rtrim($tail);
    }
}
