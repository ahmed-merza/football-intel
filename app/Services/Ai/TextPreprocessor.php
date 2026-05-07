<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Prepares raw extracted-text before it gets handed to an AI agent.
 *
 * Three knobs per call-site, all configurable in config/ai.php under
 * football_intel.preprocess:
 *   - strip_arabic — drop Arabic Unicode ranges (boilerplate on
 *     Bahraini lab reports; cuts prompt length + avoids code-switch
 *     slowdowns on smaller local models).
 *   - strip_noise — drop common lab/clinic boilerplate (page numbers,
 *     reviewer signatures, license lines, confidentiality footers,
 *     and any line that repeats 3+ times verbatim across pages).
 *     Cuts prompt size 20-40% on lab reports and stops the n8n proxy
 *     from 504-timing-out on big panels.
 *   - max_chars — hard cap (classifier only; extractor needs it all).
 *
 * Order: strip_arabic → strip_noise → truncate. Arabic first so the
 * noise stripper sees Latin-only line-starts; truncate last so the
 * cap reflects the size we'd actually send.
 *
 * Kept as a stateless service so it's trivial to unit-test and easy to
 * call from anywhere in the AI pipeline.
 */
class TextPreprocessor
{
    /**
     * Per-line noise patterns. Each is a regex that, if it matches a
     * trimmed line, drops that line from the text. Patterns are
     * deliberately conservative — over-matching costs signal, while
     * under-matching only costs a few tokens.
     *
     * @var list<string>
     */
    private const NOISE_LINE_PATTERNS = [
        // Standalone page numbers in any of the common forms:
        // "Page 1", "Page 1 of 4", "Page: 3 Of 7", "Page 3/7"
        '/^Page\s*[:.]?\s*\d+(\s+of\s+\d+|\s*\/\s*\d+)?$/i',

        // Trailing "<text>Page N" footers (HICARE-style, where the
        // letterhead is glued to the page number on a single line).
        '/Page\s*[:.]?\s*\d+(\s+of\s+\d+|\s*\/\s*\d+)?\s*$/i',

        // Reviewer / verifier / approver signature lines
        '/^(Reviewed|Verified|Authori[sz]ed|Approved|Endorsed)\s+By\s*[:.]/i',

        // Medical license lines that follow the signature
        '/^NHRA\s+License/i',
        '/^License\s+No\.?\s*[:.]/i',

        // Right Calories' confidentiality footer (every page of a plan)
        '/Confidential\s*[-—–]+\s*Right\s+Calories/i',
        '/^Confidential\s*[-—–]+/i',

        // Pure punctuation / dash / dot separators
        '/^[\s•·.,*\-—–=_]+$/u',
    ];

    /**
     * A line that appears at least this many times verbatim across the
     * document is treated as a repeated header/footer and collapsed to
     * a single occurrence. Threshold of 3 deliberately allows 2-page
     * tables to keep their column headers on each page.
     */
    private const REPEAT_HEADER_THRESHOLD = 3;

    /**
     * Classifier input — strip_arabic default ON (configurable),
     * strip_noise default ON, then optionally cap at
     * config('ai.football_intel.classifier_max_chars'). Default cap
     * is 0 (no truncation); set a positive value in .env when running
     * against smaller local models that slow down on longer prompts.
     */
    public function forClassifier(string $text): string
    {
        $text = $this->stripNullBytes($text);

        $text = $this->maybeStripArabic(
            $text,
            (bool) config('ai.football_intel.preprocess.strip_arabic.classifier'),
        );

        $text = $this->maybeStripNoise(
            $text,
            (bool) config('ai.football_intel.preprocess.strip_noise.classifier'),
        );

        return $this->truncate(
            $text,
            (int) config('ai.football_intel.classifier_max_chars', 0),
        );
    }

    /**
     * Extractor input — strip_arabic default OFF, strip_noise default
     * ON, no truncation because dropping trailing text would drop labs.
     */
    public function forExtractor(string $text): string
    {
        $text = $this->stripNullBytes($text);

        $text = $this->maybeStripArabic(
            $text,
            (bool) config('ai.football_intel.preprocess.strip_arabic.extractor'),
        );

        return $this->maybeStripNoise(
            $text,
            (bool) config('ai.football_intel.preprocess.strip_noise.extractor'),
        );
    }

    /**
     * NULL bytes are never useful signal — they're an artefact of
     * smalot/pdfparser leaking UTF-16 BE high bytes — and they crash
     * Postgres JSONB inserts (we persist the prompt text on the
     * pending_extractions row). Always-on, no toggle. Belt to the
     * extraction-time strip in ExtractTextFromAttachmentJob; this
     * covers any path that doesn't go through that job.
     */
    private function stripNullBytes(string $text): string
    {
        return str_replace("\0", '', $text);
    }

    private function maybeStripArabic(string $text, bool $shouldStrip): string
    {
        if (! $shouldStrip) {
            return $text;
        }

        // Arabic main block + supplement. Also strip Arabic-Indic digits
        // (U+0660–U+0669) — they only appear in otherwise-Arabic
        // passages we're already discarding.
        $stripped = preg_replace(
            '/[\x{0600}-\x{06FF}\x{0660}-\x{0669}\x{0750}-\x{077F}]+/u',
            ' ',
            $text,
        ) ?? $text;

        // Collapse the whitespace runs left behind by the strip — but
        // preserve line breaks so the noise-stripper can still operate
        // on a per-line basis.
        $collapsed = preg_replace('/[ \t]+/u', ' ', $stripped) ?? $stripped;

        return trim($collapsed);
    }

    /**
     * Two passes: per-line pattern matching, then verbatim-repeat
     * collapsing. Runs in O(n) over the line count so even a 50-page
     * panel stays fast.
     */
    private function maybeStripNoise(string $text, bool $shouldStrip): string
    {
        if (! $shouldStrip) {
            return $text;
        }

        $lines = preg_split('/\r?\n/', $text) ?: [];

        // Pass 1 — drop lines matching known noise patterns. Empty
        // lines are kept (we collapse runs at the end).
        $filtered = [];
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') {
                $filtered[] = '';

                continue;
            }
            $matched = false;
            foreach (self::NOISE_LINE_PATTERNS as $pattern) {
                if (preg_match($pattern, $trim) === 1) {
                    $matched = true;
                    break;
                }
            }
            if (! $matched) {
                $filtered[] = $line;
            }
        }

        // Pass 2 — count verbatim non-empty lines and collapse any that
        // appear ≥ threshold times. This catches multi-page headers /
        // footers that aren't matched by the regex set above.
        $nonEmpty = array_filter($filtered, fn (string $l): bool => trim($l) !== '');
        $counts = array_count_values($nonEmpty);
        $repeated = [];
        foreach ($counts as $line => $count) {
            if ($count >= self::REPEAT_HEADER_THRESHOLD) {
                $repeated[(string) $line] = true;
            }
        }

        $deduped = [];
        $seen = [];
        foreach ($filtered as $line) {
            if (isset($repeated[$line])) {
                if (! isset($seen[$line])) {
                    $deduped[] = $line;
                    $seen[$line] = true;
                }

                continue;
            }
            $deduped[] = $line;
        }

        // Collapse runs of 3+ blank lines down to 2 — the original
        // text often has ragged spacing after we drop noise lines.
        $output = preg_replace('/\n{3,}/', "\n\n", implode("\n", $deduped)) ?? '';

        return trim($output);
    }

    private function truncate(string $text, int $maxChars): string
    {
        if ($maxChars <= 0) {
            return $text;
        }

        return mb_substr($text, 0, $maxChars);
    }
}
