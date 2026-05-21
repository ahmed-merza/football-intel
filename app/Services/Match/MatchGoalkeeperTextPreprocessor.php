<?php

declare(strict_types=1);

namespace App\Services\Match;

/**
 * Slices the AGCFF match-report text to the "Goalkeeper" section
 * (pages 44-47 in a typical report). Same pattern as
 * {@see MatchShotEventsTextPreprocessor}:
 *
 *   - Keep the header (page 1, scorers + score) for context
 *   - Find the GOALKEEPER section heading (after the TOC entry)
 *   - End at the "Team Data" section heading
 *
 * Falls back to full text if markers aren't found.
 */
class MatchGoalkeeperTextPreprocessor
{
    private const HEADER_END_MARKER = 'Table of Contents';

    private const SECTION_START_MARKER = 'Goalkeeper';

    /**
     * The section heading reads either "Goalkeeper" alone or "Goalkeeper -
     * Saves" depending on AGCFF's layout; both match the prefix above.
     * Terminates at the next major section.
     */
    private const SECTION_END_MARKERS = [
        'Team Data',
    ];

    public function trim(string $rawText): string
    {
        $headerEnd = mb_stripos($rawText, self::HEADER_END_MARKER);
        if ($headerEnd === false) {
            return $rawText;
        }

        // "Goalkeeper" appears once in the TOC and again as the section
        // heading later. Skip the TOC occurrence by taking the 2nd hit.
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
