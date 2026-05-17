<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Player;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ODS\Reader as OdsReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

/**
 * Parses Excel/CSV files into player records, normalises messy values
 * ("170cm", "57.7 KG", "2007" → date), and runs Laravel validation per
 * row so the UI can show a preview with field-level errors before the
 * admin commits the import.
 */
class PlayerImportService
{
    /**
     * Hard cap on rows in one import — anything bigger and we'd want a
     * queue + chunked insert. Imports realistically fit one club roster.
     */
    public const MAX_ROWS = 1000;

    /**
     * Upload size cap (kilobytes). Shared with the controller validation
     * rule and surfaced to the frontend so the limit only lives in one
     * place.
     */
    public const MAX_FILE_SIZE_KB = 5120;

    /**
     * Fraction of sampled values a column must satisfy for content-based
     * detection (Arabic name, year, national ID, etc.) to claim it.
     * Tolerant enough to absorb a stray blank or "TBD".
     */
    private const COLUMN_MATCH_THRESHOLD = 0.7;

    /**
     * Headers we recognise per Player field. Lower-cased, trimmed,
     * non-alphanumerics stripped before matching. Arabic + English
     * variants live side-by-side so the admin can use whichever
     * spreadsheet they have.
     *
     * @var array<string, list<string>>
     */
    private const HEADER_MAP = [
        'full_name' => ['fullname', 'name', 'playername', 'englishname', 'nameen', 'fullnameen'],
        'name_ar' => ['namear', 'arabicname', 'nameinarabic', 'الاسم', 'الأسم', 'اسم', 'اسماللاعب'],
        'club' => ['club', 'team', 'النادي', 'الفريق'],
        'position' => ['position', 'pos', 'role', 'المركز'],
        'date_of_birth' => ['dateofbirth', 'dob', 'birthdate', 'birthday', 'birth', 'birthyear', 'yearofbirth', 'مواليد', 'تاريخالميلاد', 'سنةالميلاد'],
        'nationality' => ['nationality', 'country', 'الجنسية'],
        'height_cm' => ['height', 'heightcm', 'الطول'],
        'weight_kg' => ['weight', 'weightkg', 'الوزن'],
        'preferred_foot' => ['preferredfoot', 'foot', 'القدم'],
        'player_code' => ['playercode', 'code', 'id', 'nationalid', 'cpr', 'الرقم', 'الرقمالوطني', 'الرقمالشخصي', 'الهوية'],
        'phone' => ['phone', 'mobile', 'tel', 'telephone', 'phonenumber', 'الهاتف', 'الجوال'],
        'email' => ['email', 'mail', 'البريد', 'البريدالالكتروني'],
    ];

    /**
     * Parse the uploaded file into a header map + rows. Returns the
     * payload the UI renders during the preview step, including
     * validation errors and soft warnings keyed by row index + field.
     *
     * Errors block import; warnings (e.g. "name matches an existing
     * player") surface in the UI but the row still imports.
     *
     * @return array{
     *     headers: list<string>,
     *     mapping: array<string, int|null>,
     *     rows: list<array{
     *         row_number: int,
     *         normalized: array<string, mixed>,
     *         errors: array<string, list<string>>,
     *         warnings: list<string>,
     *         duplicate_of_row: int|null,
     *     }>,
     *     summary: array{total: int, valid: int, invalid: int, warned: int}
     * }
     */
    public function parse(UploadedFile $file): array
    {
        $rawRows = $this->readRows($file);

        if (count($rawRows) === 0) {
            return [
                'headers' => [],
                'mapping' => [],
                'rows' => [],
                'summary' => ['total' => 0, 'valid' => 0, 'invalid' => 0, 'warned' => 0],
            ];
        }

        $headerIndex = $this->findHeaderRow($rawRows);
        $headers = $headerIndex !== null ? $rawRows[$headerIndex] : [];
        $dataRows = $headerIndex !== null
            ? array_slice($rawRows, $headerIndex + 1)
            : $rawRows;

        $mapping = $this->mapHeaders($headers);
        $mapping = $this->inferUnmappedColumns($mapping, $dataRows);

        $existing = $this->loadExistingUniqueValues();
        $existingNames = $this->loadExistingNames();
        $seenCodes = [];
        $seenEmails = [];
        $seenPhones = [];
        $seenNames = [];

        $results = [];
        $rowNumber = ($headerIndex ?? -1) + 2; // 1-indexed, +1 for header

        foreach ($dataRows as $row) {
            if ($this->rowIsEmpty($row)) {
                $rowNumber++;
                continue;
            }

            if (count($results) >= self::MAX_ROWS) {
                break;
            }

            $normalized = $this->normalizeRow($row, $mapping);
            $duplicateOf = null;
            $warnings = [];

            // Within-file duplicate detection — Laravel's unique rule
            // only checks the DB, so two identical codes in the same
            // upload would both pass and one would silently win on
            // insert. We surface it as a row-level error.
            if (! empty($normalized['player_code'])) {
                $key = mb_strtolower((string) $normalized['player_code']);
                if (isset($seenCodes[$key])) {
                    $duplicateOf = $seenCodes[$key];
                } else {
                    $seenCodes[$key] = $rowNumber;
                }
            }
            if (! empty($normalized['email'])) {
                $key = mb_strtolower((string) $normalized['email']);
                if (isset($seenEmails[$key])) {
                    $duplicateOf ??= $seenEmails[$key];
                } else {
                    $seenEmails[$key] = $rowNumber;
                }
            }
            if (! empty($normalized['phone'])) {
                $key = (string) $normalized['phone'];
                if (isset($seenPhones[$key])) {
                    $duplicateOf ??= $seenPhones[$key];
                } else {
                    $seenPhones[$key] = $rowNumber;
                }
            }

            $errors = $this->validateRow($normalized, $existing);
            if ($duplicateOf !== null) {
                $errors['_row'][] = "Duplicate of row {$duplicateOf} in this file.";
            }

            // Soft warnings for name matches — the schema doesn't
            // enforce name uniqueness (common Arabic names recur
            // legitimately), so we flag but don't block.
            foreach ([$normalized['full_name'] ?? null, $normalized['name_ar'] ?? null] as $candidate) {
                if (! is_string($candidate) || $candidate === '') {
                    continue;
                }
                $key = $this->normalizeName($candidate);
                if (isset($existingNames[$key])) {
                    $warnings[] = "A player named \"{$candidate}\" already exists in your roster.";
                }
                if (isset($seenNames[$key]) && $seenNames[$key] !== $rowNumber) {
                    $warnings[] = "Same name as row {$seenNames[$key]} in this file.";
                }
                $seenNames[$key] ??= $rowNumber;
            }
            $warnings = array_values(array_unique($warnings));

            $results[] = [
                'row_number' => $rowNumber,
                'normalized' => $normalized,
                'errors' => $errors,
                'warnings' => $warnings,
                'duplicate_of_row' => $duplicateOf,
            ];

            $rowNumber++;
        }

        $valid = count(array_filter($results, static fn ($r): bool => $r['errors'] === []));
        $warned = count(array_filter($results, static fn ($r): bool => $r['warnings'] !== []));

        return [
            'headers' => array_map(static fn ($v): string => (string) $v, $headers),
            'mapping' => $mapping,
            'rows' => $results,
            'summary' => [
                'total' => count($results),
                'valid' => $valid,
                'invalid' => count($results) - $valid,
                'warned' => $warned,
            ],
        ];
    }

    /**
     * Persist only rows that pass server-side validation. The client's
     * `errors` field is not trusted — every row is re-validated here.
     * Wrapped in a transaction so a constraint failure at row N doesn't
     * leave the first N-1 inserted half-imported.
     *
     * @param  list<array{normalized: array<string, mixed>}>  $rows
     * @return array{created: int, skipped: int}
     */
    public function import(array $rows): array
    {
        $created = 0;
        $skipped = 0;

        DB::transaction(function () use ($rows, &$created, &$skipped): void {
            $existing = $this->loadExistingUniqueValues();
            // Reserved within this import — a row's value blocks later
            // rows in the same batch from reusing it.
            $reservedCodes = [];
            $reservedPhones = [];
            $reservedEmails = [];

            foreach ($rows as $row) {
                $data = $row['normalized'];
                $data['status'] ??= Player::STATUS_ACTIVE;

                $errors = $this->validateRow($data, [
                    'codes' => $existing['codes'] + $reservedCodes,
                    'phones' => $existing['phones'] + $reservedPhones,
                    'emails' => $existing['emails'] + $reservedEmails,
                ]);
                if ($errors !== []) {
                    $skipped++;
                    continue;
                }

                Player::create($data);
                if (! empty($data['player_code'])) {
                    $reservedCodes[mb_strtolower((string) $data['player_code'])] = true;
                }
                if (! empty($data['phone'])) {
                    $reservedPhones[(string) $data['phone']] = true;
                }
                if (! empty($data['email'])) {
                    $reservedEmails[mb_strtolower((string) $data['email'])] = true;
                }
                $created++;
            }
        });

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Snapshot existing player names (English + Arabic) keyed by
     * normalised form, so the preview can flag rows whose name
     * matches an already-present player. Soft check — names aren't
     * enforced unique by the schema.
     *
     * @return array<string, true>
     */
    private function loadExistingNames(): array
    {
        $set = [];
        Player::query()
            ->select(['full_name', 'name_ar'])
            ->orderBy('id')
            ->chunk(500, function ($players) use (&$set): void {
                foreach ($players as $player) {
                    foreach ([$player->full_name, $player->name_ar] as $name) {
                        if (! is_string($name) || $name === '') {
                            continue;
                        }
                        $set[$this->normalizeName($name)] = true;
                    }
                }
            });

        return $set;
    }

    /**
     * Lowercase + collapse whitespace. Avoids false negatives on
     * "ahmad  najji" vs "Ahmad Najji" while staying conservative
     * enough not to fold genuinely different names together.
     */
    private function normalizeName(string $name): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($name)) ?? $name;

        return mb_strtolower($collapsed);
    }

    /**
     * One-shot snapshot of the unique-key values currently in the
     * `players` table, used for in-memory duplicate checks during a
     * batch import. Three columns × one query each beats `Rule::unique`
     * firing 3N queries across N rows.
     *
     * @return array{codes: array<string, true>, phones: array<string, true>, emails: array<string, true>}
     */
    private function loadExistingUniqueValues(): array
    {
        /** @var array<string, true> $codes */
        $codes = Player::whereNotNull('player_code')
            ->pluck('player_code')
            ->mapWithKeys(static fn (string $v): array => [mb_strtolower($v) => true])
            ->all();
        /** @var array<string, true> $phones */
        $phones = Player::whereNotNull('phone')
            ->pluck('phone')
            ->mapWithKeys(static fn (string $v): array => [$v => true])
            ->all();
        /** @var array<string, true> $emails */
        $emails = Player::whereNotNull('email')
            ->pluck('email')
            ->mapWithKeys(static fn (string $v): array => [mb_strtolower($v) => true])
            ->all();

        return ['codes' => $codes, 'phones' => $phones, 'emails' => $emails];
    }

    /**
     * Read the uploaded file into a 2-D array of strings. Supports
     * CSV (with BOM stripping) and any spreadsheet PhpSpreadsheet
     * understands (xlsx, xls, ods).
     *
     * @return list<list<string>>
     */
    private function readRows(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if ($path === false) {
            return [];
        }

        $reader = $this->readerForExtension($file->getClientOriginalExtension());
        if ($reader === null) {
            return [];
        }

        try {
            $reader->open($path);
            $rows = [];
            $firstSheet = true;

            foreach ($reader->getSheetIterator() as $sheet) {
                if (! $firstSheet) {
                    break;
                }
                $firstSheet = false;

                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = $this->normalizeRowCells($row->toArray());
                }
            }

            return $rows;
        } catch (Throwable) {
            return [];
        } finally {
            $reader->close();
        }
    }

    private function readerForExtension(string $extension): CsvReader|XlsxReader|OdsReader|null
    {
        return match (strtolower($extension)) {
            'csv', 'txt' => new CsvReader(),
            'xlsx' => new XlsxReader(),
            'ods' => new OdsReader(),
            default => null,
        };
    }

    /**
     * Openspout returns natively typed cell values — dates as
     * `DateTimeInterface`, numbers as `int|float`, blanks as `null`.
     * Coerce them all into strings so the downstream column inference
     * and regexes have a uniform shape to work with.
     *
     * @param  list<mixed>  $cells
     * @return list<string>
     */
    private function normalizeRowCells(array $cells): array
    {
        $out = [];
        foreach ($cells as $value) {
            if ($value === null) {
                $out[] = '';

                continue;
            }
            if ($value instanceof DateTimeInterface) {
                $out[] = $value->format('Y-m-d');

                continue;
            }
            $out[] = (string) $value;
        }
        // Strip BOM from the first cell of the first row — CSVs saved
        // with a BOM otherwise prepend it to the first header name.
        if (isset($out[0])) {
            $out[0] = preg_replace('/^\xEF\xBB\xBF/u', '', $out[0]) ?? $out[0];
        }

        return $out;
    }

    /**
     * Find the first row that looks like a header. We score each row
     * by how many cells match a known header keyword; the highest
     * scoring row in the first 5 rows wins, provided it matches at
     * least one known header.
     *
     * @param  list<list<string>>  $rows
     */
    private function findHeaderRow(array $rows): ?int
    {
        $bestIndex = null;
        $bestScore = 0;

        $allKnown = array_merge(...array_values(self::HEADER_MAP));

        foreach (array_slice($rows, 0, 5, true) as $i => $row) {
            $score = 0;
            foreach ($row as $cell) {
                $normalized = $this->normalizeHeader((string) $cell);
                if ($normalized !== '' && in_array($normalized, $allKnown, true)) {
                    $score++;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIndex = $i;
            }
        }

        return $bestIndex;
    }

    /**
     * Build a column-index map for each known Player field. A value
     * of null means the field is not present in the upload.
     *
     * @param  list<string>  $headers
     * @return array<string, int|null>
     */
    private function mapHeaders(array $headers): array
    {
        $mapping = array_fill_keys(array_keys(self::HEADER_MAP), null);

        foreach ($headers as $i => $cell) {
            $normalized = $this->normalizeHeader((string) $cell);
            if ($normalized === '') {
                continue;
            }
            foreach (self::HEADER_MAP as $field => $aliases) {
                if (in_array($normalized, $aliases, true) && $mapping[$field] === null) {
                    $mapping[$field] = $i;
                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * Some uploads (a federation's free-form roster, say) only label a
     * handful of columns and leave the rest implicit. After header
     * matching, look at the actual data in unmapped columns and try
     * to recognise classic shapes: a column of long Arabic strings
     * → name_ar, a column of bare 4-digit years → date_of_birth, a
     * column of 9–10 digit numeric IDs → player_code, a column of
     * short Arabic strings → club. We sample up to 10 rows so a few
     * blanks don't throw the detection off.
     *
     * @param  array<string, int|null>  $mapping
     * @param  list<list<string>>  $rows
     * @return array<string, int|null>
     */
    private function inferUnmappedColumns(array $mapping, array $rows): array
    {
        $usedColumns = array_filter($mapping, static fn ($v): bool => $v !== null);
        $maxColumns = 0;
        foreach (array_slice($rows, 0, 20) as $row) {
            $maxColumns = max($maxColumns, count($row));
        }

        // Sample the first 10 non-empty rows once.
        $sample = [];
        foreach ($rows as $row) {
            if (! $this->rowIsEmpty($row)) {
                $sample[] = $row;
            }
            if (count($sample) >= 10) {
                break;
            }
        }

        if ($sample === []) {
            return $mapping;
        }

        // For each unmapped column, classify it. We assign each field
        // at most once and track which slots are still open as the
        // loop progresses.
        /** @var array<string, true> $open */
        $open = [
            'name_ar' => true,
            'date_of_birth' => true,
            'player_code' => true,
            'club' => true,
            'full_name' => true,
        ];

        for ($col = 0; $col < $maxColumns; $col++) {
            if (in_array($col, $usedColumns, true)) {
                continue;
            }

            $values = [];
            foreach ($sample as $row) {
                $v = trim((string) ($row[$col] ?? ''));
                if ($v !== '') {
                    $values[] = $v;
                }
            }
            if (count($values) < 2) {
                continue;
            }

            $isYearCol = $this->fractionMatches($values, fn (string $v): bool => (bool) preg_match('/^\d{4}(?:\.0+)?$/', $v) && ((int) $v) >= 1900 && ((int) $v) <= (int) date('Y'));
            $isIdCol = $this->fractionMatches($values, fn (string $v): bool => (bool) preg_match('/^\d{8,11}$/', preg_replace('/\D/', '', $v) ?? ''));
            $isRowNumberCol = $this->fractionMatches($values, fn (string $v): bool => (bool) preg_match('/^\d{1,3}(?:\.0+)?$/', $v) && (int) $v < 1000);
            // Names are long + multi-word (e.g. "أحمد ابراهيم ناجي المريسي").
            // Club names are usually a single word or two ("الرفاع",
            // "الرفاع الشرقي") so the space count separates them — `str_word_count`
            // doesn't recognise Arabic glyphs as letters, so we count spaces directly.
            $arabicLong = $this->fractionMatches($values, fn (string $v): bool => $this->isMostlyArabic($v)
                && mb_strlen($v) >= 12
                && substr_count(trim($v), ' ') >= 2);
            $arabicShort = $this->fractionMatches($values, fn (string $v): bool => $this->isMostlyArabic($v) && mb_strlen($v) < 20);
            $latinName = $this->fractionMatches($values, fn (string $v): bool => (bool) preg_match('/^[\p{Latin}\s\.\'-]{3,}$/u', $v));

            // Order matters — most specific shape first.
            if (isset($open['date_of_birth']) && $isYearCol) {
                $mapping['date_of_birth'] = $col;
                unset($open['date_of_birth']);
                continue;
            }
            if (isset($open['player_code']) && $isIdCol && ! $isYearCol && ! $isRowNumberCol) {
                $mapping['player_code'] = $col;
                unset($open['player_code']);
                continue;
            }
            if (isset($open['name_ar']) && $arabicLong) {
                $mapping['name_ar'] = $col;
                unset($open['name_ar']);
                continue;
            }
            if (isset($open['club']) && $arabicShort && ! $arabicLong) {
                $mapping['club'] = $col;
                unset($open['club']);
                continue;
            }
            if (isset($open['full_name']) && $latinName) {
                $mapping['full_name'] = $col;
                unset($open['full_name']);
                continue;
            }
        }

        return $mapping;
    }

    /**
     * @param  list<string>  $values
     */
    private function fractionMatches(array $values, callable $predicate): bool
    {
        $hits = 0;
        foreach ($values as $v) {
            if ($predicate($v)) {
                $hits++;
            }
        }

        return $hits / count($values) >= self::COLUMN_MATCH_THRESHOLD;
    }

    private function isMostlyArabic(string $value): bool
    {
        $arabicChars = preg_match_all('/\p{Arabic}/u', $value);
        $letters = preg_match_all('/\p{L}/u', $value);
        if ($letters === 0) {
            return false;
        }

        return ($arabicChars / $letters) >= 0.5;
    }

    private function normalizeHeader(string $raw): string
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return '';
        }

        // Drop everything that isn't a letter (Arabic counts) so
        // "Height (cm)" / "Body Fat %" / "الاسم:" all collapse to
        // a comparable token.
        $stripped = preg_replace('/[^\p{L}]+/u', '', $trimmed);

        return mb_strtolower((string) $stripped);
    }

    /**
     * @param  list<string>  $row
     */
    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Apply the column mapping and clean up each value into the type
     * the Player schema expects.
     *
     * @param  list<string>  $row
     * @param  array<string, int|null>  $mapping
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row, array $mapping): array
    {
        $out = [];

        foreach ($mapping as $field => $columnIndex) {
            $raw = $columnIndex !== null && isset($row[$columnIndex])
                ? trim((string) $row[$columnIndex])
                : '';

            $out[$field] = match ($field) {
                'height_cm' => $this->parseHeight($raw),
                'weight_kg' => $this->parseWeight($raw),
                'date_of_birth' => $this->parseDob($raw),
                'preferred_foot' => $this->parseFoot($raw),
                'email' => $raw !== '' ? mb_strtolower($raw) : null,
                default => $raw !== '' ? $raw : null,
            };
        }

        // full_name is required by the schema. If the upload only has
        // an Arabic name, mirror it so the row passes validation rather
        // than failing every player on a totally fixable issue.
        if (($out['full_name'] ?? null) === null && ! empty($out['name_ar'])) {
            $out['full_name'] = (string) $out['name_ar'];
        }

        $out['status'] = Player::STATUS_ACTIVE;

        return $out;
    }

    private function parseHeight(string $raw): ?int
    {
        if ($raw === '') {
            return null;
        }
        // Pull the first number out so "170 cm", "170cm", "1.70m" all work.
        if (! preg_match('/-?\d+(?:[.,]\d+)?/', $raw, $m)) {
            return null;
        }
        $value = (float) str_replace(',', '.', $m[0]);
        $lower = mb_strtolower($raw);
        $hasExplicitCm = str_contains($lower, 'cm') || str_contains($lower, 'mm');
        // Heuristic: a unitless value < 3 (e.g. 1.78) is metres. Skip
        // the conversion when the user wrote "cm" explicitly — "2cm"
        // is literally 2cm, which should then fail range validation.
        if (! $hasExplicitCm && $value > 0 && $value < 3) {
            $value *= 100;
        }

        return (int) round($value);
    }

    private function parseWeight(string $raw): ?float
    {
        if ($raw === '') {
            return null;
        }
        if (! preg_match('/-?\d+(?:[.,]\d+)?/', $raw, $m)) {
            return null;
        }

        return (float) str_replace(',', '.', $m[0]);
    }

    /**
     * Accept a real date string, an ISO date, or a bare 4-digit year
     * (in which case we use Jan 1 of that year — adequate for an age
     * estimate when the file only carries مواليد = 2007).
     */
    private function parseDob(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }

        // Year only.
        if (preg_match('/^(\d{4})(?:\.0+)?$/', $raw, $m)) {
            $year = (int) $m[1];
            if ($year >= 1900 && $year <= (int) date('Y')) {
                return sprintf('%04d-01-01', $year);
            }
        }

        try {
            return CarbonImmutable::parse($raw)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function parseFoot(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }
        $lower = mb_strtolower($raw);

        return match (true) {
            str_contains($lower, 'both') || str_contains($lower, 'كلتا') => 'both',
            str_starts_with($lower, 'l') || str_contains($lower, 'يسار') => 'left',
            str_starts_with($lower, 'r') || str_contains($lower, 'يمن') => 'right',
            default => null,
        };
    }

    /**
     * Run row validation. Uniqueness is checked against the preloaded
     * `$existing` snapshot rather than fresh `Rule::unique` queries —
     * for a 1000-row import that's the difference between 3 queries and
     * 3000.
     *
     * @param  array<string, mixed>  $row
     * @param  array{codes: array<string, true>, phones: array<string, true>, emails: array<string, true>}  $existing
     * @return array<string, list<string>>
     */
    private function validateRow(array $row, array $existing): array
    {
        $validator = Validator::make($row, [
            'full_name' => ['required', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'club' => ['nullable', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:50'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'height_cm' => ['nullable', 'integer', 'between:100,230'],
            'weight_kg' => ['nullable', 'numeric', 'between:30,200'],
            'preferred_foot' => ['nullable', Rule::in(['right', 'left', 'both'])],
            'player_code' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'status' => ['nullable', Rule::in([
                Player::STATUS_ACTIVE, Player::STATUS_INACTIVE, Player::STATUS_ARCHIVED,
            ])],
        ]);

        /** @var array<string, list<string>> $errors */
        $errors = $validator->passes() ? [] : $validator->errors()->toArray();

        $code = $row['player_code'] ?? null;
        if (is_string($code) && $code !== '' && isset($existing['codes'][mb_strtolower($code)])) {
            $errors['player_code'][] = 'A player with this code already exists.';
        }
        $phone = $row['phone'] ?? null;
        if (is_string($phone) && $phone !== '' && isset($existing['phones'][$phone])) {
            $errors['phone'][] = 'A player with this phone already exists.';
        }
        $email = $row['email'] ?? null;
        if (is_string($email) && $email !== '' && isset($existing['emails'][mb_strtolower($email)])) {
            $errors['email'][] = 'A player with this email already exists.';
        }

        return $errors;
    }
}
