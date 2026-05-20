<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * Single dispatch point for every Football-Intel agent. Lets us route
 * around laravel/ai's provider abstraction when the operator has set up
 * an out-of-band proxy (e.g. n8n wrapping a Claude CLI) that doesn't
 * conform to the Anthropic API shape laravel/ai expects.
 *
 * Decision rule: when the agent reports `provider() === 'n8n'`, we hit
 * N8nClaudeGateway and serialize the agent's JSON schema into the
 * system prompt. Otherwise we go through the agent's standard
 * Promptable::prompt() path and pull the structured/text result off
 * the response object.
 *
 * Tests should swap N8nClaudeGateway via the container OR call
 * Http::fake() — the gateway is a thin Http wrapper.
 */
class AgentRouter
{
    public function __construct(private N8nClaudeGateway $n8n) {}

    /**
     * @param  array{kind?: string, record_id?: int|null, analysis_id?: int|null}  $context  Forwarded
     *                                                                                       to the n8n gateway so the pending_extractions row carries enough
     *                                                                                       metadata for the async-callback path. Direct-Anthropic / Ollama
     *                                                                                       paths ignore context (they don't have a callback story).
     * @return array<string, mixed>
     */
    public function send(Agent $agent, string $userPrompt, array $context = []): array
    {
        $provider = $this->resolveProvider($agent);

        if ($provider === 'n8n') {
            return $this->n8n->send(
                systemPrompt: (string) $agent->instructions(),
                userPrompt: $userPrompt,
                schema: $agent instanceof HasStructuredOutput
                    ? $this->compileSchema($agent->schema(new JsonSchemaTypeFactory))
                    : null,
                context: $context,
                model: $this->resolveModel($agent),
            );
        }

        // Standard laravel/ai path — Anthropic, Ollama, Gemini, etc.
        // AgentResponse already extends TextResponse, so `text` is always
        // safe to read; structured agents return the subclass with the
        // typed payload.
        $response = $agent->prompt($userPrompt);

        if ($response instanceof StructuredAgentResponse) {
            return $response->toArray();
        }

        return ['text' => $response->text];
    }

    private function resolveProvider(Agent $agent): string
    {
        return method_exists($agent, 'provider')
            ? (string) $agent->provider()
            : (string) config('ai.default');
    }

    /**
     * Pull the agent's preferred Claude model so the n8n workflow can use
     * the right one per agent (Haiku for the classifier, Opus for extractor
     * + nutritionist). Returns null when the agent doesn't declare one —
     * the workflow falls back to its own default in that case.
     */
    private function resolveModel(Agent $agent): ?string
    {
        if (! method_exists($agent, 'model')) {
            return null;
        }
        $model = (string) $agent->model();

        return $model === '' ? null : $model;
    }

    /**
     * Convert the agent's JsonSchema property map to a plain JSON-Schema
     * `object` definition the n8n proxy can serialize.
     *
     * @param  array<string, Type>  $properties
     * @return array<string, mixed>
     */
    private function compileSchema(array $properties): array
    {
        $required = [];
        $compiled = [];
        foreach ($properties as $name => $type) {
            $payload = $type->toArray();
            // toArray() leaves "required" on the type, but in JSON Schema it
            // belongs on the parent object. Promote + drop.
            if (! empty($payload['required'])) {
                $required[] = $name;
                unset($payload['required']);
            }
            $compiled[$name] = $payload;
        }

        return [
            'type' => 'object',
            'properties' => $compiled,
            ...($required === [] ? [] : ['required' => $required]),
            'additionalProperties' => false,
        ];
    }
}
