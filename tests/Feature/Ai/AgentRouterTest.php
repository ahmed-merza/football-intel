<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\Agents\DocumentClassifier;
use App\Models\RecordCategory;
use App\Services\Ai\AgentRouter;
use App\Services\Ai\N8nClaudeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentRouterTest extends TestCase
{
    use RefreshDatabase;

    private const N8N_URL = 'https://n8n.example.test/webhook/abc';

    public function test_anthropic_path_calls_through_laravel_ai_and_returns_structured_array(): void
    {
        config([
            'ai.football_intel.providers.classifier' => 'anthropic',
            'ai.football_intel.models.classifier' => 'claude-haiku-4-5',
        ]);

        DocumentClassifier::fake([[
            'category' => RecordCategory::BLOOD_TEST,
            'confidence' => 0.92,
            'reasoning' => 'Lab analytes with units.',
        ]]);

        $router = app(AgentRouter::class);
        $result = $router->send(new DocumentClassifier, 'Haemoglobin 14.2 g/dL');

        $this->assertSame(RecordCategory::BLOOD_TEST, $result['category']);
        $this->assertSame(0.92, $result['confidence']);
    }

    public function test_n8n_path_calls_the_gateway_and_returns_parsed_json(): void
    {
        config([
            'ai.football_intel.providers.classifier' => 'n8n',
            'ai.providers.n8n.url' => self::N8N_URL,
            'ai.providers.n8n.timeout' => 60,
        ]);

        Http::fake([
            self::N8N_URL => Http::response([[
                'code' => 0,
                'signal' => null,
                'stdout' => "```json\n{\"category\": \"inbody\", \"confidence\": 0.88, \"reasoning\": \"weight + body fat present\"}\n```",
                'stderr' => '',
            ]]),
        ]);

        $router = app(AgentRouter::class);
        $result = $router->send(new DocumentClassifier, 'Weight 72 kg, Body Fat 11%');

        $this->assertSame('inbody', $result['category']);
        $this->assertSame(0.88, $result['confidence']);

        Http::assertSent(function ($request): bool {
            $payload = json_decode($request->body(), true);
            // Ensure the agent's instructions reached n8n's system_prompt
            $this->assertStringContainsString('classify football-player documents', $payload['system_prompt']);
            // And the agent's schema got serialised into the system prompt
            $this->assertStringContainsString('blood_test', $payload['system_prompt']);
            $this->assertSame('Weight 72 kg, Body Fat 11%', $payload['user_prompt']);

            return true;
        });
    }

    public function test_router_uses_gateway_via_container_so_tests_can_swap_it(): void
    {
        config(['ai.football_intel.providers.classifier' => 'n8n']);

        $stub = $this->createMock(N8nClaudeGateway::class);
        $stub->expects($this->once())
            ->method('send')
            ->willReturn(['category' => 'other', 'confidence' => 0.5, 'reasoning' => 'mock']);

        $this->app->instance(N8nClaudeGateway::class, $stub);

        $router = app(AgentRouter::class);
        $result = $router->send(new DocumentClassifier, 'noise');

        $this->assertSame('other', $result['category']);
    }
}
