<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\Agents\DocumentClassifier;
use App\Models\RecordCategory;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Tests\TestCase;

class DocumentClassifierTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function runClassifier(string $text): array
    {
        /** @var StructuredAgentResponse $response */
        $response = (new DocumentClassifier)->prompt($text);

        return $response->toArray();
    }

    public function test_it_returns_the_category_from_a_canned_response(): void
    {
        DocumentClassifier::fake([
            [
                'category' => RecordCategory::BLOOD_TEST,
                'confidence' => 0.94,
                'reasoning' => 'Contains Hb, ferritin, vitamin D with units and reference ranges.',
            ],
        ]);

        $result = $this->runClassifier(
            'Haemoglobin 14.2 g/dL (ref 13.0–17.5), Ferritin 33 ng/mL, Vitamin D 28 ng/mL...',
        );

        $this->assertSame(RecordCategory::BLOOD_TEST, $result['category']);
        $this->assertSame(0.94, $result['confidence']);
        $this->assertStringContainsString('Hb', $result['reasoning']);
    }

    public function test_it_cycles_through_multiple_canned_responses(): void
    {
        DocumentClassifier::fake([
            ['category' => RecordCategory::BLOOD_TEST, 'confidence' => 0.9, 'reasoning' => 'labs'],
            ['category' => RecordCategory::INBODY, 'confidence' => 0.9, 'reasoning' => 'body comp'],
            ['category' => RecordCategory::GPS_WEARABLE, 'confidence' => 0.8, 'reasoning' => 'GPS'],
        ]);

        $this->assertSame(RecordCategory::BLOOD_TEST, $this->runClassifier('some blood text')['category']);
        $this->assertSame(RecordCategory::INBODY, $this->runClassifier('some inbody text')['category']);
        $this->assertSame(RecordCategory::GPS_WEARABLE, $this->runClassifier('some gps text')['category']);
    }

    public function test_it_supports_closure_based_dynamic_responses(): void
    {
        DocumentClassifier::fake(function (string $prompt): array {
            $slug = str_contains(strtolower($prompt), 'ferritin')
                ? RecordCategory::BLOOD_TEST
                : RecordCategory::OTHER;

            return [
                'category' => $slug,
                'confidence' => $slug === RecordCategory::BLOOD_TEST ? 0.95 : 0.5,
                'reasoning' => 'heuristic fake',
            ];
        });

        $this->assertSame(
            RecordCategory::BLOOD_TEST,
            $this->runClassifier('Ferritin 33 ng/mL')['category'],
        );
        $this->assertSame(
            RecordCategory::OTHER,
            $this->runClassifier('nothing recognisable')['category'],
        );
    }

    public function test_assert_prompted_captures_the_text(): void
    {
        DocumentClassifier::fake([
            ['category' => RecordCategory::BLOOD_TEST, 'confidence' => 0.9, 'reasoning' => 'labs'],
        ]);

        (new DocumentClassifier)->prompt('Haemoglobin 14.2 g/dL, Ferritin 33 ng/mL');

        DocumentClassifier::assertPrompted(
            fn ($prompt) => str_contains($prompt->prompt, 'Ferritin'),
        );
    }

    public function test_prevent_stray_prompts_throws_when_no_fake_is_set(): void
    {
        DocumentClassifier::fake()->preventStrayPrompts();

        $this->expectException(\RuntimeException::class);

        (new DocumentClassifier)->prompt('unexpected call');
    }

    public function test_provider_and_model_resolve_from_config(): void
    {
        config([
            'ai.football_intel.providers.classifier' => 'ollama',
            'ai.football_intel.models.classifier' => 'qwen3:14b',
        ]);

        $agent = new DocumentClassifier;

        $this->assertSame('ollama', $agent->provider());
        $this->assertSame('qwen3:14b', $agent->model());

        config([
            'ai.football_intel.providers.classifier' => 'anthropic',
            'ai.football_intel.models.classifier' => 'claude-haiku-4-5',
        ]);

        $this->assertSame('anthropic', $agent->provider());
        $this->assertSame('claude-haiku-4-5', $agent->model());
    }

    public function test_schema_enumerates_the_eight_canonical_categories(): void
    {
        $agent = new DocumentClassifier;
        $schema = $agent->schema(new JsonSchemaTypeFactory);

        $this->assertArrayHasKey('category', $schema);
        $this->assertArrayHasKey('confidence', $schema);
        $this->assertArrayHasKey('reasoning', $schema);

        $json = $schema['category']->toArray();
        $this->assertSame('string', $json['type']);
        $this->assertEqualsCanonicalizing(
            [
                RecordCategory::BLOOD_TEST,
                RecordCategory::INBODY,
                RecordCategory::GPS_WEARABLE,
                RecordCategory::NUTRITION_PLAN,
                RecordCategory::HYDRATION_SUPPLEMENT_PLAN,
                RecordCategory::COACH_FEEDBACK,
                RecordCategory::MATCH_ACTIVITY,
                RecordCategory::OTHER,
            ],
            $json['enum'],
        );
    }
}
