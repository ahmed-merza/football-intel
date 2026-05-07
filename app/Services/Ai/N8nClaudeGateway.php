<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\PendingExtraction;
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
     * @param  array{kind?: string, record_id?: int|null}  $context  Hooks for the
     *                                                               pending_extractions row so the callback handler knows what to do with
     *                                                               the eventual result. `kind` defaults to 'unknown'; `record_id` is
     *                                                               optional (classifier calls don't have one yet).
     * @return array<string, mixed> Parsed JSON from the model when $schema is set,
     *                              or ['text' => '<stdout>'] when null.
     */
    public function send(string $systemPrompt, string $userPrompt, ?array $schema = null, array $context = []): array
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
        } catch (\Throwable $e) {
            // Network-level failure (DNS, connect refused, TLS, etc.) —
            // not a 504. n8n probably never received the request, so a
            // callback won't arrive. Mark failed and re-throw.
            $pending->update([
                'status' => PendingExtraction::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 1000),
                'processed_at' => Carbon::now(),
            ]);

            throw $e;
        }

        // 504 = nginx in front of n8n gave up before n8n responded.
        // n8n is still running and will hit our callback when done.
        // Surface a typed exception so the caller can react.
        if ($response->status() === 504 && $callbackUrl !== '') {
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
