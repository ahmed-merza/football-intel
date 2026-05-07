<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\N8nClaudeGateway;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class N8nClaudeGatewayTest extends TestCase
{
    private const URL = 'https://n8n.example.com/webhook/abc';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ai.providers.n8n.url' => self::URL,
            'ai.providers.n8n.timeout' => 60,
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
