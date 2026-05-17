<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai;

use App\Models\PendingExtraction;
use App\Services\Ai\CallbackPendingException;
use App\Services\Ai\N8nClaudeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class N8nClaudeGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://n8n.example.com/webhook/abc';

    private const CALLBACK_URL = 'https://app.example.com/webhooks/n8n/extraction';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ai.providers.n8n.url' => self::URL,
            'ai.providers.n8n.timeout' => 60,
            'ai.providers.n8n.callback_url' => self::CALLBACK_URL,
        ]);
    }

    public function test_freeform_call_returns_text_payload(): void
    {
        Http::fake([
            self::URL => Http::response(
                [['code' => 0, 'signal' => null, 'stdout' => 'Hello there', 'stderr' => '']],
            ),
        ]);

        $result = (new N8nClaudeGateway)->send(
            systemPrompt: 'Say hello.',
            userPrompt: 'Hi',
        );

        $this->assertSame(['text' => 'Hello there'], $result);
    }

    public function test_structured_call_strips_markdown_fences_and_parses_json(): void
    {
        Http::fake([
            self::URL => Http::response([[
                'code' => 0,
                'signal' => null,
                'stdout' => "```json\n{\"category\": \"blood_test\", \"confidence\": 0.94}\n```",
                'stderr' => '',
            ]]),
        ]);

        $result = (new N8nClaudeGateway)->send(
            systemPrompt: 'Classify documents.',
            userPrompt: 'Haemoglobin 14.2',
            schema: [
                'type' => 'object',
                'properties' => [
                    'category' => ['type' => 'string'],
                    'confidence' => ['type' => 'number'],
                ],
            ],
        );

        $this->assertSame(['category' => 'blood_test', 'confidence' => 0.94], $result);
    }

    public function test_structured_call_recovers_when_model_adds_prose_around_json(): void
    {
        Http::fake([
            self::URL => Http::response([[
                'code' => 0,
                'signal' => null,
                'stdout' => 'Sure, here is the answer: {"category": "inbody"} done!',
                'stderr' => '',
            ]]),
        ]);

        $result = (new N8nClaudeGateway)->send(
            systemPrompt: 'x',
            userPrompt: 'y',
            schema: ['type' => 'object'],
        );

        $this->assertSame(['category' => 'inbody'], $result);
    }

    public function test_structured_call_throws_when_response_is_not_json(): void
    {
        Http::fake([
            self::URL => Http::response([[
                'code' => 0,
                'signal' => null,
                'stdout' => 'I refuse to answer.',
                'stderr' => '',
            ]]),
        ]);

        $this->expectException(RuntimeException::class);

        (new N8nClaudeGateway)->send(
            systemPrompt: 'x',
            userPrompt: 'y',
            schema: ['type' => 'object'],
        );
    }

    public function test_throws_when_subprocess_exit_code_is_nonzero(): void
    {
        Http::fake([
            self::URL => Http::response([[
                'code' => 1,
                'signal' => null,
                'stdout' => '',
                'stderr' => 'authentication failed',
            ]]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/code=1.+authentication failed/');

        (new N8nClaudeGateway)->send('x', 'y');
    }

    public function test_throws_when_response_shape_is_unexpected(): void
    {
        Http::fake([
            self::URL => Http::response(['unexpected' => 'shape']),
        ]);

        $this->expectException(RuntimeException::class);
        (new N8nClaudeGateway)->send('x', 'y');
    }

    public function test_throws_when_url_is_not_configured(): void
    {
        config(['ai.providers.n8n.url' => null]);

        $this->expectException(RuntimeException::class);
        (new N8nClaudeGateway)->send('x', 'y');
    }

    public function test_read_timeout_throws_callback_pending_and_keeps_row_pending(): void
    {
        // Simulates the cURL 28 read timeout we see when n8n's nginx
        // doesn't return a clean 504 — Guzzle just gives up waiting and
        // wraps the failure as ConnectionException. n8n's still running
        // upstream, so we must NOT mark the pending row as failed.
        Http::fake(function (): void {
            throw new ConnectionException('cURL error 28: Operation timed out after 60000 ms');
        });

        try {
            (new N8nClaudeGateway)->send(systemPrompt: 'x', userPrompt: 'y');
            $this->fail('Expected CallbackPendingException');
        } catch (CallbackPendingException $e) {
            $this->assertNotEmpty($e->correlationId);
        }

        $pending = PendingExtraction::firstOrFail();
        $this->assertSame(PendingExtraction::STATUS_PENDING, $pending->status);
        $this->assertNull($pending->processed_at);
    }

    public function test_connect_failure_marks_row_failed_and_rethrows(): void
    {
        // DNS / connect-refused / TLS errors mean n8n never received the
        // request, so no callback is coming. Fail loudly so Horizon's
        // failed() hook fires.
        Http::fake(function (): void {
            throw new ConnectionException('cURL error 6: Could not resolve host: n8n.example.com');
        });

        $this->expectException(ConnectionException::class);

        try {
            (new N8nClaudeGateway)->send(systemPrompt: 'x', userPrompt: 'y');
        } finally {
            $pending = PendingExtraction::firstOrFail();
            $this->assertSame(PendingExtraction::STATUS_FAILED, $pending->status);
        }
    }

    public function test_http_504_throws_callback_pending(): void
    {
        Http::fake([
            self::URL => Http::response('gateway timeout', 504),
        ]);

        $this->expectException(CallbackPendingException::class);

        (new N8nClaudeGateway)->send(systemPrompt: 'x', userPrompt: 'y');
    }

    public function test_request_uses_get_with_body_and_includes_schema_when_provided(): void
    {
        Http::fake([
            self::URL => Http::response([[
                'code' => 0,
                'signal' => null,
                'stdout' => '{"ok": true}',
                'stderr' => '',
            ]]),
        ]);

        (new N8nClaudeGateway)->send(
            systemPrompt: 'You are a sorter.',
            userPrompt: 'sort this',
            schema: ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']]],
        );

        Http::assertSent(function ($request): bool {
            $payload = json_decode($request->body(), true);
            $this->assertIsArray($payload);
            $this->assertArrayHasKey('system_prompt', $payload);
            $this->assertArrayHasKey('user_prompt', $payload);
            $this->assertSame('GET', $request->method());
            // Schema must be embedded in the system prompt
            $this->assertStringContainsString('OUTPUT FORMAT', $payload['system_prompt']);
            $this->assertStringContainsString('boolean', $payload['system_prompt']);

            return true;
        });
    }
}
