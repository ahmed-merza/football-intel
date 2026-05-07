<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Throwable;

/**
 * Translates raw exception output from an AI agent call into a sentence
 * the admin can act on. Source messages tend to leak HTTP status codes,
 * x-api-key fragments, JSON envelopes, and stack frames — none of which
 * should hit the UI.
 *
 * The full original exception is always logged separately by the caller
 * (see Log::error in the job's catch blocks); this helper only shapes
 * what gets stored on the DB row + rendered to the user.
 */
class AgentErrorMessage
{
    /**
     * @return string Short, user-facing reason. ≤200 chars, no API
     *                keys, no stack frames.
     */
    public static function humanise(Throwable $exception): string
    {
        $raw = $exception->getMessage();

        $message = match (true) {
            str_contains($raw, 'x-api-key header is required'),
            str_contains($raw, 'authentication_error'),
            str_contains($raw, 'status code 401') => 'The AI provider rejected the request — credentials missing or expired. Check the configured provider in .env (AI_PROVIDER_*) and that the matching API key is set.',

            str_contains($raw, '403') => 'The AI provider denied access to this request. The configured key may not have permission for the model in use.',

            str_contains($raw, '404') => 'The AI provider returned 404 — the configured URL or model name is wrong. Check AI_N8N_URL or the AI_MODEL_* values.',

            str_contains($raw, '429') => 'The AI provider is rate-limiting our requests. Wait a moment and retry.',

            str_contains($raw, '504'),
            str_contains($raw, 'Gateway Time-out') => 'The AI proxy gave up waiting for the model to finish. The prompt may be too heavy for the configured model — try a faster one or bump the upstream timeout.',

            str_contains($raw, 'has timed out'),
            str_contains($raw, 'Operation timed out'),
            str_contains($raw, 'cURL error 28') => 'The AI request timed out. Cold-start models or large prompts can run past the 5-minute ceiling — retry, or pick a faster model.',

            str_contains($raw, 'Could not resolve host'),
            str_contains($raw, 'Connection refused') => 'Could not reach the AI provider. Check that the URL is correct and the service is online.',

            str_contains($raw, 'AI_N8N_URL is not configured') => 'AI_N8N_URL is not configured — add the n8n webhook URL to .env, or pick a different provider.',

            str_contains($raw, 'not valid JSON'),
            str_contains($raw, 'response was not valid JSON') => 'The AI provider returned a response we could not parse. The model may have ignored the JSON-only instruction; retry, or try a stronger model.',

            str_contains($raw, 'Need at least one blood test') => $raw, // already friendly

            default => 'The AI request failed unexpectedly. The full error has been logged for review.',
        };

        return mb_substr(self::redact($message), 0, 200);
    }

    /**
     * Defensive scrub — even if we missed a pattern above, never let
     * an api-key-shaped string leak through to the DB / UI.
     */
    private static function redact(string $message): string
    {
        // Anthropic + Voyage style keys: long base64-ish runs after a
        // colon/equals, or after literal "key" / "token" markers.
        $patterns = [
            '/sk-ant-[a-zA-Z0-9_-]{20,}/',
            '/(api[_-]?key|x-api-key|authorization|bearer)[\s:=]+[A-Za-z0-9+\/_=.-]{16,}/i',
        ];

        return preg_replace($patterns, '[redacted]', $message) ?? $message;
    }
}
