<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PendingExtraction;
use App\Models\PlayerRecord;
use App\Models\RecordCategory;
use App\Services\Ai\N8nClaudeGateway;
use App\Services\Medical\ExtractionApplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Receives the asynchronous result of an n8n extraction call. n8n always
 * tries to respond synchronously to our outbound request, but when the
 * proxy in front of n8n 504s before Claude finishes the sync response is
 * lost — n8n then POSTs the result here so we can close the loop.
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

        // Apply to the owning record (if any) via the kind-specific
        // applier. classifier callbacks have no owning record yet —
        // they're consumed by ClassifyAttachmentJob, which we'll wire
        // for async in a follow-up. For now we just close the row.
        if ($pending->record_id !== null) {
            /** @var PlayerRecord|null $record */
            $record = PlayerRecord::find($pending->record_id);

            if ($record !== null) {
                try {
                    $this->applyByKind($pending->kind, $record, $parsed);
                } catch (Throwable $e) {
                    $pending->update([
                        'status' => PendingExtraction::STATUS_FAILED,
                        'error' => 'apply_failed: '.mb_substr($e->getMessage(), 0, 500),
                        'result' => $parsed,
                        'processed_at' => Carbon::now(),
                    ]);

                    return response()->json(['error' => 'apply_failed'], 500);
                }
            }
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
