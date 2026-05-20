<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MatchReport;
use App\Models\NutritionistAnalysis;
use App\Models\PendingExtraction;
use App\Models\PlayerRecord;
use App\Models\RecordCategory;
use App\Services\Ai\N8nClaudeGateway;
use App\Services\Match\MatchReportApplier;
use App\Services\Medical\ExtractionApplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Receives the asynchronous result of any n8n call — document extractions
 * AND nutritionist analyses both flow through here. n8n always tries to
 * respond synchronously to our outbound request, but when the proxy in
 * front of n8n times out before Claude finishes (or our own client read
 * timeout fires), the sync response is lost — n8n then POSTs the result
 * here so we can close the loop. Dispatch is by `kind` on the pending row:
 * extraction kinds apply to a PlayerRecord; nutritionist_analysis applies
 * to a NutritionistAnalysis.
 *
 * Idempotency: a callback for an already-completed correlation is a
 * no-op (200 OK). The sync HTTP response is the fast path; this is the
 * insurance lane. Both can fire for the same call without double-applying.
 *
 * Auth: shared-secret in the `X-Callback-Secret` header, configured via
 * AI_N8N_CALLBACK_SECRET on this side and on the n8n workflow's HTTP
 * Request node. Empty secret → endpoint refuses every call (config
 * misconfiguration is the more dangerous failure mode than dropping
 * legit ones). Constant-time compare so a length leak through timing
 * isn't a concern.
 */
class N8nWebhookController extends Controller
{
    public function __construct(
        private ExtractionApplier $applier,
        private MatchReportApplier $matchApplier,
        private N8nClaudeGateway $gateway,
    ) {}

    public function extraction(Request $request): JsonResponse
    {
        if (! $this->verifySecret($request)) {
            Log::warning('n8n callback rejected: bad secret', ['ip' => $request->ip()]);

            return response()->json(['error' => 'unauthorized'], 401);
        }

        $correlationId = (string) $request->input('correlation_id', '');
        if ($correlationId === '' || ! Str::isUuid($correlationId)) {
            // Reject malformed correlation ids early so the column's
            // uuid type check doesn't crash the lookup with a 22P02.
            return response()->json(['error' => 'malformed correlation_id'], 422);
        }

        /** @var PendingExtraction|null $pending */
        $pending = PendingExtraction::where('correlation_id', $correlationId)->first();
        if ($pending === null) {
            // Could be a callback for a row already expired + cleaned up
            // by the reaper, or a stray request. We log + acknowledge so
            // n8n doesn't keep retrying.
            Log::info('n8n callback for unknown correlation', ['correlation_id' => $correlationId]);

            return response()->json(['status' => 'unknown_correlation'], 200);
        }

        // Idempotent: already-terminal rows are a no-op.
        if ($pending->isTerminal()) {
            return response()->json(['status' => "noop_{$pending->status}"], 200);
        }

        $rawResult = $request->input('result');
        if (! is_array($rawResult)) {
            $pending->update([
                'status' => PendingExtraction::STATUS_FAILED,
                'error' => 'callback result was not an array',
                'processed_at' => Carbon::now(),
            ]);

            return response()->json(['error' => 'result must be an array'], 422);
        }

        try {
            // n8n sends the SSH-node payload as-is, same shape as a sync
            // response. The gateway's parser handles fence stripping,
            // JSON extraction, and code-zero check.
            $hasSchema = (bool) ($pending->request['has_schema'] ?? false);
            $parsed = $this->gateway->parseN8nPayload($rawResult, $hasSchema ? [] : null);
        } catch (Throwable $e) {
            $pending->update([
                'status' => PendingExtraction::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 1000),
                'result' => $rawResult,
                'processed_at' => Carbon::now(),
            ]);

            return response()->json(['error' => 'parse_failed'], 422);
        }

        // Three owner shapes: extraction kinds carry a record_id, the
        // nutritionist_analysis kind carries an analysis_id, the
        // match_report kind carries a match_report_id. Dispatch on the
        // kind first (it's the authoritative signal), falling back to
        // owner FKs. Missing owners are a no-op (already-deleted row,
        // classifier kind).
        try {
            if ($pending->kind === PendingExtraction::KIND_NUTRITIONIST_ANALYSIS) {
                $this->applyToAnalysis($pending, $parsed);
            } elseif ($pending->kind === PendingExtraction::KIND_MATCH_REPORT) {
                $this->applyToMatchReport($pending, $parsed);
            } elseif ($pending->record_id !== null) {
                /** @var PlayerRecord|null $record */
                $record = PlayerRecord::find($pending->record_id);
                if ($record !== null) {
                    $this->applyByKind($pending->kind, $record, $parsed);
                }
            }
        } catch (Throwable $e) {
            $pending->update([
                'status' => PendingExtraction::STATUS_FAILED,
                'error' => 'apply_failed: '.mb_substr($e->getMessage(), 0, 500),
                'result' => $parsed,
                'processed_at' => Carbon::now(),
            ]);

            return response()->json(['error' => 'apply_failed'], 500);
        }

        $pending->update([
            'status' => PendingExtraction::STATUS_COMPLETED,
            'result' => $parsed,
            'processed_at' => Carbon::now(),
        ]);

        return response()->json(['status' => 'ok'], 200);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyByKind(string $kind, PlayerRecord $record, array $payload): void
    {
        match ($kind) {
            PendingExtraction::KIND_BLOOD_TEST,
            RecordCategory::BLOOD_TEST => $this->applier->applyBloodTest($record, $payload),

            PendingExtraction::KIND_INBODY,
            RecordCategory::INBODY => $this->applier->applyInBody($record, $payload),

            PendingExtraction::KIND_NUTRITION_PLAN,
            RecordCategory::NUTRITION_PLAN => $this->applier->applyNutritionPlan($record, $payload),

            // No-op for now: classifier callbacks fall through to the
            // pending row close, no record-side apply. ClassifyAttachmentJob
            // will pick up async support in its own commit.
            default => null,
        };
    }

    /**
     * Mirror of {@see applyToAnalysis} for the match-report owner shape.
     * Promote the report from `awaiting_callback` to `extracted` and
     * write the payload via {@see MatchReportApplier::applyExtraction}.
     * Reports already past `awaiting_callback` (sync response beat us
     * here, or the admin retried and a newer run wrote first) are a
     * no-op so we don't clobber fresher state.
     *
     * @param  array<string, mixed>  $payload
     */
    private function applyToMatchReport(PendingExtraction $pending, array $payload): void
    {
        if ($pending->match_report_id === null) {
            return;
        }

        /** @var MatchReport|null $report */
        $report = MatchReport::find($pending->match_report_id);
        if ($report === null || $report->status !== MatchReport::STATUS_AWAITING_CALLBACK) {
            return;
        }

        $this->matchApplier->applyExtraction($report, $payload);
    }

    /**
     * Mirror the inline-success path in GenerateNutritionistAnalysisJob:
     * promote the pre-allocated analysis row from pending to completed
     * and stash the model output. Already-terminal rows (admin re-ran
     * before the callback landed, or a sync response beat us here) are
     * a no-op so we don't clobber fresher state.
     *
     * @param  array<string, mixed>  $payload
     */
    private function applyToAnalysis(PendingExtraction $pending, array $payload): void
    {
        if ($pending->analysis_id === null) {
            return;
        }

        /** @var NutritionistAnalysis|null $analysis */
        $analysis = NutritionistAnalysis::find($pending->analysis_id);
        if ($analysis === null || $analysis->status !== NutritionistAnalysis::STATUS_PENDING) {
            return;
        }

        $summary = is_string($payload['summary'] ?? null)
            ? mb_substr($payload['summary'], 0, 1000)
            : null;

        $analysis->update([
            'status' => NutritionistAnalysis::STATUS_COMPLETED,
            'summary_text' => $summary,
            'payload' => $payload,
            'generated_at' => Carbon::now(),
            'error' => null,
        ]);
    }

    private function verifySecret(Request $request): bool
    {
        $expected = (string) config('ai.providers.n8n.callback_secret', '');
        if ($expected === '') {
            // No secret configured = closed. Refuse rather than accept
            // anonymous callbacks.
            return false;
        }

        $provided = (string) $request->header('X-Callback-Secret', '');

        return hash_equals($expected, $provided);
    }
}
