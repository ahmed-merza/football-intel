<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PendingExtraction;
use App\Models\PlayerRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Background sweeper for pending_extractions rows that never got their
 * callback. n8n calls the webhook back when Claude finishes, but if
 * something on the n8n side fails (workflow disabled, network, server
 * restart) the callback never lands and the pending row sits there
 * forever — the Timeline shows "awaiting callback" indefinitely.
 *
 * The expires_at timestamp on each row is the deadline. Beyond that,
 * we mark the row `expired` and flip the owning record back to a
 * normal `extraction_failed` state so the admin can re-run from the
 * Timeline. Conservative window (15 min default, configurable via
 * AI_N8N_CALLBACK_EXPIRY_MINUTES) — bigger than any realistic Claude
 * call, smaller than forever.
 *
 * Wired into the scheduler in routes/console.php; also runnable
 * manually for one-shot cleanups.
 */
class ReapStalePendingExtractionsCommand extends Command
{
    protected $signature = 'ai:reap-pending
        {--dry-run : Print what would be expired without writing}';

    protected $description = 'Mark expired pending_extractions rows + recover their owning records.';

    public function handle(): int
    {
        $now = Carbon::now();
        $stale = PendingExtraction::where('status', PendingExtraction::STATUS_PENDING)
            ->where('expires_at', '<', $now)
            ->with('record')
            ->get();

        if ($stale->isEmpty()) {
            $this->info('No stale pending extractions.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->line(sprintf(
            '%s %d stale pending row(s)%s',
            $dryRun ? 'Would expire' : 'Expiring',
            $stale->count(),
            $dryRun ? ' (dry run — no writes)' : '...',
        ));

        $expired = 0;
        $recovered = 0;
        foreach ($stale as $pending) {
            $age = (int) $now->diffInMinutes($pending->created_at);
            $this->line(sprintf(
                '  - id=%d  kind=%s  record_id=%s  age=%dm',
                $pending->id,
                $pending->kind,
                $pending->record_id ?? '-',
                $age,
            ));

            if ($dryRun) {
                continue;
            }

            $pending->update([
                'status' => PendingExtraction::STATUS_EXPIRED,
                'error' => 'callback never arrived within expires_at window',
                'processed_at' => $now,
            ]);
            $expired++;

            if ($this->recoverOwningRecord($pending->record)) {
                $recovered++;
            }
        }

        if ($dryRun) {
            return self::SUCCESS;
        }

        Log::info('PendingExtraction reaper finished', [
            'expired' => $expired,
            'recovered' => $recovered,
        ]);

        $this->info(sprintf('Expired %d row(s); flipped %d record(s) to failed.', $expired, $recovered));

        return self::SUCCESS;
    }

    /**
     * Move the owning PlayerRecord out of the awaiting-callback limbo
     * and into a plain `extraction_failed` state so the Timeline
     * shows a Re-extract button instead of an indefinite spinner.
     * Returns true when we actually flipped a record (skipped when
     * the record was deleted, isn't actually awaiting, or has already
     * been reconciled by some other path).
     */
    private function recoverOwningRecord(?PlayerRecord $record): bool
    {
        if ($record === null) {
            return false;
        }

        $extracted = $record->extracted ?? [];
        if (! ($extracted['awaiting_callback'] ?? false)) {
            return false;
        }

        unset(
            $extracted['awaiting_callback'],
            $extracted['callback_correlation_id'],
        );
        $extracted['extraction_failed'] = true;
        $extracted['extraction_failed_at'] = Carbon::now()->toIso8601String();
        $extracted['extraction_failure_reason'] =
            'Extraction timed out — the callback from the AI proxy never arrived. Re-extract to try again.';

        $record->update(['extracted' => $extracted]);

        return true;
    }
}
