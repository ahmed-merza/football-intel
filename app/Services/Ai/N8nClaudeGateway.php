<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\PendingExtraction;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Calls a self-hosted n8n webhook that wraps Claude via a CLI inside n8n.
 * Useful when the operator already has a Claude subscription and doesn't
 * want a separate Anthropic API key — the webhook becomes the proxy.
 * Configured via AI_N8N_URL.
 *
 * Wire shape, established by probing the live endpoint:
 *   - HTTP method: GET (counterintuitive, but that's what the workflow accepts)
 *   - Body: {"system_prompt": "...", "user_prompt": "...",
 *            "callback_url": "...", "correlation_id": "..."} as JSON
 *   - Response: [{"code": 0, "signal": null, "stdout": "...", "stderr": ""}]
 *   - The actual model output is in `[0].stdout`. The CLI wraps JSON in
 *     ```json fences; we strip them before json_decode.
 *
 * Async insurance: every call sends a callback_url + a fresh correlation_id
 * and pre-writes a pending_extractions row. n8n always tries to respond
 * synchronously; if the proxy in front of n8n 504s before the response
 * arrives, n8n still POSTs the result to our callback endpoint when Claude
 * finishes — we throw CallbackPendingException so the caller can stamp
 * "awaiting callback" on the owning entity instead of failing. The webhook
 * controller closes the pending row when the late result arrives.
 *
 * Structured-output isn't supported natively by the webhook, so when the
 * caller passes a schema we embed it into the system prompt as a strict
 * "respond with ONLY this JSON shape" instruction. Less ironclad than
 * laravel/ai's HasStructuredOutput on the Anthropic API directly, but
 * the workhorse models (Haiku) follow it reliably enough.
 */
class N8nClaudeGateway
{
    /**
     * @param  array<string, mixed>|null  $schema  JSON-Schema-shaped object describing
     *                                             the desired response shape; null = freeform text
     * @param  array{kind?: string, record_id?: int|null, analysis_id?: int|null, match_report_id?: int|null}  $context  Hooks for
     *                                                                                                                  the pending_extractions row so the callback handler knows what to do
     *                                                                                                                  with the eventual result. `kind` defaults to 'unknown';
     *                                                                                                                  `record_id` / `analysis_id` / `match_report_id` are mutually exclusive owners
     *                                                                                                                  (extraction calls carry record_id, NutritionistAssistant calls carry analysis_id,
     *                                                                                                                  match-report extraction carries match_report_id).
     * @param  string|null  $model  Claude model identifier (e.g. 'claude-opus-4-7',
     *                              'claude-haiku-4-5', or short aliases like 'opus' /
     *                              'haiku'). Forwarded to the n8n workflow which passes
     *                              it as `--model <value>` to the Claude CLI. null →
     *                              workflow falls back to its own default.
     * @return array<string, mixed> Parsed JSON from the model when $schema is set,
     *                              or ['text' => '<stdout>'] when null.
     */
    public function send(string $systemPrompt, string $userPrompt, ?array $schema = null, array $context = [], ?string $model = null): array
    {
        $url = (string) config('ai.providers.n8n.url');
        if ($url === '') {
            throw new RuntimeException('AI_N8N_URL is not configured.');
        }

        $effectiveSystemPrompt = $this->buildSystemPrompt($systemPrompt, $schema);
        $callbackUrl = (string) config('ai.providers.n8n.callback_url', '');
        $correlationId = (string) Str::uuid();

        $pending = PendingExtraction::create([
            'correlation_id' => $correlationId,
            'record_id' => $context['record_id'] ?? null,
            'analysis_id' => $context['analysis_id'] ?? null,
            'kind' => (string) ($context['kind'] ?? 'unknown'),
            'status' => PendingExtraction::STATUS_PENDING,
            'request' => [
                'system_prompt' => $effectiveSystemPrompt,
                'user_prompt' => $userPrompt,
                'has_schema' => $schema !== null,
            ],
            'expires_at' => Carbon::now()->addMinutes(
                (int) config('ai.providers.n8n.callback_expiry_minutes', 15),
            ),
        ]);

        $body = [
            'system_prompt' => $effectiveSystemPrompt,
            'user_prompt' => $userPrompt,
        ];
        if ($model !== null && $model !== '') {
            // Per-call model selection. The n8n workflow reads this and
            // passes it through as `--model <value>` to the Claude CLI;
            // missing → the workflow defaults (haiku).
            $body['model'] = $model;
        }
        if ($callbackUrl !== '') {
            // Only opt into the async insurance when a callback endpoint
            // is actually configured. Lets local dev / CI run without
            // the webhook stack wired.
            $body['callback_url'] = $callbackUrl;
            $body['correlation_id'] = $correlationId;
        }

        try {
            $response = Http::timeout((int) config('ai.providers.n8n.timeout', 300))
                ->withBody(
                    json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'application/json',
                )
                ->send('GET', $url);
        } catch (ConnectionException $e) {
            // Two flavours of ConnectionException end up here:
            //   - Read timeout (curl 28: "Operation timed out") — n8n
            //     accepted the request and is still working; the callback
            //     will eventually land. Same async-insurance path as a 504.
            //   - Connect-level failure (DNS, connect refused, TLS) — n8n
            //     never got the request, so no callback is coming. Fail.
            // We can't tell them apart from the exception type alone, so
            // fall back to the message. Worst case for a misclassified
            // connect failure: the reaper marks it expired in 15 min.
            if ($callbackUrl !== '' && $this->isReadTimeout($e)) {
                throw new CallbackPendingException($correlationId, previous: $e);
            }
            $pending->update([
                'status' => PendingExtraction::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 1000),
                'processed_at' => Carbon::now(),
            ]);

            throw $e;
        } catch (\Throwable $e) {
            $pending->update([
                'status' => PendingExtraction::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 1000),
                'processed_at' => Carbon::now(),
            ]);

            throw $e;
        }

        // Proxy-emitted timeouts (504 from nginx, 522/524 from Cloudflare)
        // mean the proxy gave up but n8n is still running upstream and
        // will hit our callback when done. Surface a typed exception so
        // the caller can react.
        if ($callbackUrl !== '' && in_array($response->status(), [504, 522, 524], true)) {
            throw new CallbackPendingException($correlationId);
        }

        if (! $response->successful()) {
            $pending->update([
                'status' => PendingExtraction::STATUS_FAILED,
                'error' => sprintf(
                    'HTTP %d: %s',
                    $response->status(),
                    mb_substr((string) $response->body(), 0, 500),
                ),
                'processed_at' => Carbon::now(),
            ]);
            $response->throw();
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            $pending->update([
                'status' => PendingExtraction::STATUS_FAILED,
                'error' => 'n8n response was not a JSON array',
                'processed_at' => Carbon::now(),
            ]);

            throw new RuntimeException('Unexpected n8n response shape: '.mb_substr((string) $response->body(), 0, 200));
        }

        $parsed = $this->parseN8nPayload($payload, $schema);

        $pending->update([
            'status' => PendingExtraction::STATUS_COMPLETED,
            'result' => $parsed,
            'processed_at' => Carbon::now(),
        ]);

        return $parsed;
    }

    /**
     * Public so the webhook controller can re-use the same parser when
     * a callback POST arrives — the result shape is identical to the
     * sync response.
     *
     * @param  array<int, mixed>  $payload  The raw n8n response body (an array of items).
     * @param  array<string, mixed>|null  $schema
     * @return array<string, mixed>
     */
    public function parseN8nPayload(array $payload, ?array $schema): array
    {
        if (! isset($payload[0]) || ! is_array($payload[0])) {
            throw new RuntimeException('Unexpected n8n response shape (no items).');
        }

        $entry = $payload[0];
        $code = $entry['code'] ?? null;
        if ($code !== 0) {
            $stderr = (string) ($entry['stderr'] ?? '');

            throw new RuntimeException("n8n claude exited with code={$code}: ".mb_substr($stderr, 0, 200));
        }

        $stdout = (string) ($entry['stdout'] ?? '');

        if ($schema === null) {
            return ['text' => trim($stdout)];
        }

        return $this->parseStructuredJson($stdout);
    }

    /**
     * Heuristic: cURL surfaces read-timeout failures as error 28
     * ("Operation timed out"). Connect-level failures use different
     * codes (6 = could not resolve, 7 = could not connect, 35/60 = TLS).
     * Matching on the cURL code keeps us robust against Guzzle's
     * message format drifting between releases.
     */
    private function isReadTimeout(ConnectionException $e): bool
    {
        return str_contains($e->getMessage(), 'cURL error 28')
            || str_contains(strtolower($e->getMessage()), 'operation timed out');
    }

    /**
     * @param  array<string, mixed>|null  $schema
     */
    private function buildSystemPrompt(string $userSystemPrompt, ?array $schema): string
    {
        if ($schema === null) {
            return $userSystemPrompt;
        }

        $serialized = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $userSystemPrompt
            ."\n\n--- OUTPUT FORMAT ---\n"
            .'Respond with ONLY a single JSON object that validates against the schema below. '
            .'Do NOT wrap it in markdown code fences. Do NOT include any explanation, prefix, or suffix. '
            ."Keys absent from the schema must not appear in the response.\n\n"
            ."Schema:\n".$serialized;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseStructuredJson(string $stdout): array
    {
        // Strip ```json or ``` fences that the CLI likes to add even
        // when explicitly told not to.
        $cleaned = preg_replace('/```(?:json)?\s*|\s*```/u', '', $stdout) ?? $stdout;
        $cleaned = trim($cleaned);

        // If the model added prose around the JSON, snip from the first {
        // to the last } so we still get something parseable.
        $first = strpos($cleaned, '{');
        $last = strrpos($cleaned, '}');
        if ($first !== false && $last !== false && $last > $first) {
            $cleaned = substr($cleaned, $first, $last - $first + 1);
        }

        try {
            $parsed = json_decode($cleaned, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException(
                'n8n response was not valid JSON: '.mb_substr($stdout, 0, 300).' — '.$e->getMessage(),
                previous: $e,
            );
        }

        if (! is_array($parsed)) {
            throw new RuntimeException('n8n response decoded to a non-array: '.gettype($parsed));
        }

        return $parsed;
    }
}
