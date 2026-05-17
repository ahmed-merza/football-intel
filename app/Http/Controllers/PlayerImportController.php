<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PlayerImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bulk-roster import. Two-step flow: upload → server parses + validates
 * and returns the preview payload back into the same Inertia page; the
 * admin confirms and a second POST commits the rows.
 */
class PlayerImportController extends Controller
{
    public function __construct(private readonly PlayerImportService $importer) {}

    public function create(): Response
    {
        return Inertia::render('players/import', [
            'preview' => null,
        ]);
    }

    public function preview(Request $request): Response
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.PlayerImportService::MAX_FILE_SIZE_KB,
                'mimes:csv,txt,xlsx,xls,ods',
            ],
        ]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $request->file('file');

        $preview = $this->importer->parse($file);

        return Inertia::render('players/import', [
            'preview' => [
                'filename' => $file->getClientOriginalName(),
                ...$preview,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.normalized' => ['required', 'array'],
        ]);

        // Drop any other fields the client may have included — only
        // `normalized` flows into the import. Errors get re-computed
        // server-side; trusting the client here would let a malicious
        // POST claim every row is valid.
        $rows = array_map(
            static fn (array $row): array => ['normalized' => $row['normalized']],
            $validated['rows'],
        );

        $result = $this->importer->import($rows);

        Inertia::flash('toast', [
            'type' => $result['created'] > 0 ? 'success' : 'error',
            'message' => $result['skipped'] > 0
                ? __(':created players imported, :skipped skipped.', $result)
                : __(':created players imported.', ['created' => $result['created']]),
        ]);

        return to_route('players.index');
    }
}
