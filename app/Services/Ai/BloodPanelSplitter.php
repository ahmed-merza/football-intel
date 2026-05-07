<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Splits a blood-test panel into per-section chunks so each chunk can
 * go through the extractor independently. Big federation panels print
 * 5-10 sub-panels (CBC + Lipid + Liver + Renal + Thyroid + Vitamins +
 * Iron + Diabetic) on one PDF; asking the AI to emit 50+ typed rows
 * in one response runs past the n8n proxy's nginx timeout. Splitting
 * trades extra API calls for responses that fit comfortably inside
 * the timeout window.
 *
 * Each chunk carries the document header (lab name, patient name,
 * sample date, column names) so the extractor has enough context to
 * resolve the section's analytes.
 *
 * Returns a single-element list when no recognised section markers
 * are found OR when only one section is present — caller should treat
 * that as "no split needed, run extractor once".
 */
class BloodPanelSplitter
{
    /**
     * Section headers we recognise. Order doesn't matter; we sort by
     * the position the marker appears in the text. Matching is
     * case-insensitive and anchored to the start of a line so an
     * analyte name that mentions e.g. "iron" inside a sentence
     * doesn't trigger a split.
     *
     * @var list<string>
     */
    private const SECTION_MARKERS = [
        // Hematology — both US and UK spellings, plus the parenthesised
        // form some labs use ("COMPLETE BLOOD COUNT (CBC)").
        'COMPLETE HEMOGRAM',
        'COMPLETE BLOOD COUNT',
        'COMPLETE BLOOD COUNT (CBC)',
        'HEMATOLOGY',
        'HAEMATOLOGY',
        // Chemistry buckets — labs use very different umbrella terms.
        'BIOCHEMISTRY',
        'CHEMISTRY UNIT',
        'CHEMISTRY PROFILE',
        'CLINICAL PATHOLOGY',
        // Lipid panel
        'LIPID PROFILE',
        'LIPID PANEL',
        // Liver function — bare and Al-Kindi-style "LFT (...)" form.
        'LIVER FUNCTION TEST',
        'LIVER FUNCTION TESTS',
        'LIVER PANEL',
        'LFT (LIVER FUNCTION TEST)',
        // Renal / kidney function
        'RENAL FUNCTION TEST',
        'RENAL FUNCTION TESTS',
        'KIDNEY FUNCTION TEST',
        'RFT (RENAL FUNCTION TEST)',
        // Thyroid panel
        'THYROID FUNCTION TEST',
        'THYROID FUNCTION TESTS',
        'THYROID PANEL',
        'TFT (THYROID FUNCTION TEST)',
        // Diabetic profile + HbA1c
        'DIABETIC PROFILE',
        'DIABETES PROFILE',
        'GLYCOSYLATED HAEMOGLOBIN',
        'GLYCATED HAEMOGLOBIN',
        'GLYCOSYLATED HAEMOGLOBIN (HBA1C)',
        // Iron studies
        'IRON PROFILE',
        'IRON STUDIES',
        // Vitamins
        'VITAMIN PROFILE',
        'VITAMINS',
        'VITAMIN PANEL',
        // Cardiac + electrolytes + coagulation
        'CARDIAC MARKERS',
        'ELECTROLYTES',
        'COAGULATION PROFILE',
        'INFLAMMATORY MARKERS',
        // Other umbrella sections that show up as their own page on
        // federation panels — keep them as separate chunks so the
        // extractor isn't trying to digest them in the same call.
        'URINE ANALYSIS',
        'URINALYSIS',
        'IMMUNOLOGY',
        'MICROBIOLOGY',
        'SEROLOGY',
        'HORMONE PROFILE',
    ];

    /**
     * Minimum useful chunk size in characters. A "chunk" smaller than
     * this is almost always a parent marker followed immediately by a
     * child marker (e.g. "HAEMATOLOGY" with "COMPLETE BLOOD COUNT
     * (CBC)" 5 lines below) — the parent's section is essentially
     * empty so we merge it forward into the next chunk to keep the
     * actual body content together.
     */
    private const MIN_CHUNK_BYTES = 250;

    /**
     * @return list<string> One chunk per detected section; the document
     *                      header (everything before the first marker)
     *                      is prepended to each section so the
     *                      extractor sees lab name + patient + column
     *                      headers in every call.
     */
    public function split(string $text): array
    {
        if ($text === '') {
            return [$text];
        }

        $matches = $this->findSectionPositions($text);
        if (count($matches) < 2) {
            // 0 or 1 section — splitting buys nothing, send whole.
            return [$text];
        }

        // Document header is everything before the first section marker
        // (lab address, patient name, sample date, column header row).
        // Prepended to every chunk so each extractor call has full
        // context, even for the lipid panel that doesn't reprint
        // patient info.
        //
        // We use byte-based substr (not mb_substr) because PCRE
        // returns byte offsets in PREG_OFFSET_CAPTURE; mixing the two
        // drifts past ~2500 chars on text with any multi-byte content.
        $headerEnd = $matches[0]['pos'];
        $header = trim(substr($text, 0, $headerEnd));

        // Build sections (without prepended header — that comes after
        // the merge pass so we don't count header bytes against the
        // min-chunk threshold).
        $sections = [];
        $count = count($matches);
        $textLen = strlen($text);
        for ($i = 0; $i < $count; $i++) {
            $start = $matches[$i]['pos'];
            $end = $matches[$i + 1]['pos'] ?? $textLen;
            $sections[] = trim(substr($text, $start, $end - $start));
        }

        // Merge tiny sections forward into the next one. Walks
        // backwards so a chain of tiny-then-tiny still collapses
        // correctly. The last section (with no successor) is left
        // alone — usually the document tail.
        $merged = [];
        $carry = '';
        foreach ($sections as $i => $section) {
            $isLast = $i === count($sections) - 1;
            if (! $isLast && mb_strlen($section) < self::MIN_CHUNK_BYTES) {
                $carry = $carry === '' ? $section : $carry."\n\n".$section;

                continue;
            }
            $merged[] = $carry === '' ? $section : $carry."\n\n".$section;
            $carry = '';
        }
        if ($carry !== '') {
            // Trailing carry that never met threshold — append to the
            // last merged chunk so its content isn't lost.
            $last = array_pop($merged) ?? '';
            $merged[] = $last === '' ? $carry : $last."\n\n".$carry;
        }

        // Prepend the document header to every chunk.
        return array_map(
            fn (string $section): string => $header === '' ? $section : $header."\n\n".$section,
            $merged,
        );
    }

    /**
     * Walk the marker list and return every match's byte offset. We
     * sort by position because the marker list isn't in document
     * order — a panel may have LIPID before THYROID or vice-versa.
     *
     * @return list<array{marker: string, pos: int}>
     */
    private function findSectionPositions(string $text): array
    {
        $positions = [];
        foreach (self::SECTION_MARKERS as $marker) {
            // Match the marker as a complete line (case-insensitive).
            // Compound markers with parens are listed explicitly in
            // SECTION_MARKERS — no flexible regex needed, which
            // avoids false matches inside analyte names.
            $pattern = '/^\s*'.preg_quote($marker, '/').'\s*$/im';
            if (preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE) === false) {
                continue;
            }
            foreach ($m[0] as $hit) {
                $positions[] = ['marker' => $marker, 'pos' => (int) $hit[1]];
            }
        }

        usort($positions, fn (array $a, array $b): int => $a['pos'] <=> $b['pos']);

        // Deduplicate by line: a header like "LFT (LIVER FUNCTION TEST)"
        // matches both the LFT marker and the LIVER FUNCTION TEST marker
        // at slightly different positions on the same line. We want one
        // chunk boundary, not two. Snap each match to its line-start
        // byte offset, then keep the first match per line.
        //
        // strrpos + substr both work in bytes — matches PREG_OFFSET_CAPTURE.
        $seen = [];
        $deduped = [];
        foreach ($positions as $p) {
            $lineStart = strrpos(substr($text, 0, $p['pos']), "\n");
            $lineKey = $lineStart === false ? 0 : $lineStart + 1;
            if (! isset($seen[$lineKey])) {
                // Use the line start as the chunk boundary so the
                // header itself stays at the top of the section.
                $deduped[] = ['marker' => $p['marker'], 'pos' => $lineKey];
                $seen[$lineKey] = true;
            }
        }

        return $deduped;
    }
}
