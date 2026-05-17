<?php

use App\Http\Controllers\AlertController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\N8nWebhookController;
use App\Http\Controllers\NutritionistAnalysisController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\PlayerImportController;
use App\Http\Controllers\PlayerRecordController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SubmissionController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Hard delete is a GDPR-style purge — intentionally routed through a
    // dedicated flow later. For now the admin can only archive + restore.
    Route::get('players/search', [PlayerController::class, 'search'])->name('players.search');
    // Bulk roster intake — CSV/XLSX upload → preview with per-row
    // validation → confirm to commit. Defined before the resource
    // routes so /players/import doesn't get caught by /players/{player}.
    Route::get('players/import', [PlayerImportController::class, 'create'])
        ->name('players.import.create');
    Route::post('players/import/preview', [PlayerImportController::class, 'preview'])
        ->name('players.import.preview');
    Route::post('players/import', [PlayerImportController::class, 'store'])
        ->name('players.import.store');
    // JSON pagination endpoint for the profile Timeline tab.
    Route::get('players/{player}/timeline', [PlayerController::class, 'timeline'])
        ->name('players.timeline');
    Route::resource('players', PlayerController::class)->except(['destroy']);
    Route::post('players/{player}/archive', [PlayerController::class, 'archive'])->name('players.archive');
    Route::post('players/{player}/restore', [PlayerController::class, 'restore'])->name('players.restore');

    // Submission intake — the admin drops files on the player profile and
    // they flow through Submission → Attachment → extraction job chain.
    Route::post('players/{player}/submissions', [SubmissionController::class, 'store'])
        ->name('players.submissions.store');

    // Re-queue a failed or stuck submission's extract → classify chain.
    Route::post('submissions/{submission}/retry', [SubmissionController::class, 'retry'])
        ->name('submissions.retry');
    // Discard a failed submission (files + rows). Only operates on failed.
    Route::delete('submissions/{submission}', [SubmissionController::class, 'destroy'])
        ->name('submissions.destroy');

    // Re-run structured extraction on one record (e.g. after the
    // extractor failed silently or the admin tweaked a prompt).
    Route::post('records/{record}/re-extract', [PlayerRecordController::class, 'reExtract'])
        ->name('records.re-extract');
    // Admin sign-off on a record — flips reviewed=true with reviewer
    // + timestamp. Lives on the Documents tab. DELETE undoes.
    Route::post('records/{record}/review', [PlayerRecordController::class, 'markReviewed'])
        ->name('records.review');
    Route::delete('records/{record}/review', [PlayerRecordController::class, 'unmarkReviewed'])
        ->name('records.review.undo');

    // Auth-gated streaming for files on the private disk.
    Route::get('attachments/{attachment}', [AttachmentController::class, 'download'])
        ->name('attachments.download');

    // Nutritionist Assistant — triggers a fresh agent run for a
    // player. Stores result in nutritionist_analyses; the AI analysis
    // tab on the profile renders the latest + history.
    Route::post('players/{player}/nutritionist-analyses', [NutritionistAnalysisController::class, 'store'])
        ->name('players.nutritionist-analyses.store');

    // Review queue — admin inbox for low-confidence classifications and
    // misclassified records that flowed through the AI pipeline.
    Route::get('review', [ReviewController::class, 'index'])->name('review.index');
    Route::post('review/{record}/confirm', [ReviewController::class, 'confirm'])
        ->name('review.confirm');

    // Alerts — open + acknowledged queue for medical flags raised by
    // the rule-based flagger and the Nutritionist Assistant.
    Route::get('alerts', [AlertController::class, 'index'])->name('alerts.index');
    Route::post('alerts/{alert}/acknowledge', [AlertController::class, 'acknowledge'])
        ->name('alerts.acknowledge');
    Route::delete('alerts/{alert}/acknowledge', [AlertController::class, 'unacknowledge'])
        ->name('alerts.unacknowledge');

    // Coming-soon stubs — the sidebar links land here so the admin can
    // see the planned scope. Real pages ship in their own phases.
    Route::inertia('knowledge', 'coming-soon/knowledge')->name('knowledge.index');
    Route::inertia('reports', 'coming-soon/reports')->name('reports.index');
});

// n8n callback for asynchronous extraction results. Outside the auth
// group — n8n authenticates via the X-Callback-Secret header. CSRF
// exemption is in bootstrap/app.php (`webhooks/n8n/*`).
Route::post('webhooks/n8n/extraction', [N8nWebhookController::class, 'extraction'])
    ->name('webhooks.n8n.extraction');

require __DIR__.'/settings.php';
