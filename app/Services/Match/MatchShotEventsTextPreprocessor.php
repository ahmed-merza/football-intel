<?php

declare(strict_types=1);

namespace App\Services\Match;

use App\Ai\Agents\MatchShotEventsExtractor;

/**
 * Slices an AGCFF match-report PDF's extracted text down to just the
 * "Shot Details" section that {@see MatchShotEventsExtractor}
 * needs. The Shot Details section sits between "Shots & Goals" (page 8-9)
 * and "Event Map" / "Distribution: Passes" (page 22+).
 *
 * Includes both teams' Shot Details (each side gets its own subsection).
 *
 * Strategy:
 *   - Find the FIRST "Shot Details" heading after the Table of Contents
 *     (the TOC mention of "Shot Details" is the earliest occurrence; we
 *     need the actual section heading which is later).
 *   - End at the FIRST "Event Map" or "Distribution : Passes" heading
 *     after that — whichever comes first.
 *   - Always keep the header (page 1, scorers + final score) so the
 *     model has context for "this is the match these shots are from".
 *
 * If markers can't be located, fall back to the full text — better an
 * over-large prompt than a missing-data extraction.
 */
class MatchShotEventsTextPreprocessor
{
    private const HEADER_END_MARKER = 'Table of Contents';

    private const SECTION_START_MARKER = 'Shot Details';

    /**
     * Section ends at whichever of these markers appears FIRST after the
     * start marker. AGCFF's section ordering: Shot Details → Event Map →
     * Distribution : Passes → Passes : Details → Duels → ... → Team Data.
     */
    private const SECTION_END_MARKERS = [
        'Event Map',
        'Distribution : Passes',
        'Distribution: Passes',
        'Team Data',
    ];

    public function trim(string $rawText): string
    {
        $headerEnd = mb_stripos($rawText, self::HEADER_END_MARKER);
        if ($headerEnd === false) {
            return $rawText;
        }

        // "Shot Details" appears in the Table of Contents as a "section
        // name + page number" line, AND again as the actual section
        // heading later. Skip the first occurrence (TOC entry) and use
        // the second — that's the real heading. Robust against TOCs of
        // any length, unlike a fixed byte offset.
        $sectionStart = $this->nthOccurrence($rawText, self::SECTION_START_MARKER, 2, $headerEnd + 1);
        if ($sectionStart === null) {
            return $rawText;
        }

        $sectionEnd = $this->firstMarkerAfter($rawText, self::SECTION_END_MARKERS, $sectionStart);

        $header = mb_substr($rawText, 0, $headerEnd);
        $section = $sectionEnd !== null
            ? mb_substr($rawText, $sectionStart, $sectionEnd - $sectionStart)
            : mb_substr($rawText, $sectionStart);

        return rtrim($header)
            ."\n\n--- (intermediate sections omitted by preprocessor) ---\n\n"
            .rtrim($section);
    }

    /**
     * Find the n-th occurrence of $needle in $haystack starting from
     * $from. Returns null if there aren't that many occurrences.
     */
    private function nthOccurrence(string $haystack, string $needle, int $n, int $from): ?int
    {
        $cursor = $from;
        for ($i = 0; $i < $n; $i++) {
            $pos = mb_stripos($haystack, $needle, $cursor);
            if ($pos === false) {
                return null;
            }
            if ($i === $n - 1) {
                return $pos;
            }
            $cursor = $pos + mb_strlen($needle);
        }

        return null;
    }

    /**
     * @param  list<string>  $markers
     */
    private function firstMarkerAfter(string $rawText, array $markers, int $from): ?int
    {
        $earliest = null;
        foreach ($markers as $marker) {
            $pos = mb_stripos($rawText, $marker, $from + 1);
            if ($pos === false) {
                continue;
            }
            if ($earliest === null || $pos < $earliest) {
                $earliest = $pos;
            }
        }

        return $earliest;
    }
}
