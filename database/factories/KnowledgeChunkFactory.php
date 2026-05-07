<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Always attaches a 1024-dim pseudo-random embedding so `->create()` satisfies
 * the NOT NULL `embedding` column. The values are noise, not semantic vectors
 * — don't use them for similarity tests. For RAG correctness, fake the
 * embedding provider or insert real vectors via `withEmbedding($array)`.
 *
 * @extends Factory<KnowledgeChunk>
 */
class KnowledgeChunkFactory extends Factory
{
    protected $model = KnowledgeChunk::class;

    public function definition(): array
    {
        return [
            'document_id' => KnowledgeDocument::factory(),
            'chunk_index' => $this->faker->numberBetween(0, 30),
            'content' => $this->faker->paragraphs(3, true),
            // Postgres' vector_in parses the text literal "[x,y,z,...]" from a
            // bound string parameter, so the raw string is a valid insert value.
            'embedding' => $this->randomEmbeddingLiteral(1024),
            'metadata' => [
                'section' => $this->faker->randomElement(['Protocol', 'Timing', 'Dosage', 'Safety']),
                'page' => $this->faker->numberBetween(1, 8),
            ],
        ];
    }

    /**
     * Explicit random embedding at a custom dimensionality (e.g. to match an
     * alternate embedding provider's output shape).
     */
    public function withRandomEmbedding(int $dimensions = 1024): static
    {
        return $this->state(fn (): array => [
            'embedding' => $this->randomEmbeddingLiteral($dimensions),
        ]);
    }

    /**
     * Deterministic embedding from a caller-supplied array. Use when a test
     * needs two chunks to have a known cosine relationship.
     *
     * @param  list<float>  $vector
     */
    public function withEmbedding(array $vector): static
    {
        return $this->state(fn (): array => [
            'embedding' => '['.implode(',', $vector).']',
        ]);
    }

    private function randomEmbeddingLiteral(int $dimensions): string
    {
        $values = [];
        for ($i = 0; $i < $dimensions; $i++) {
            $values[] = round(mt_rand() / mt_getrandmax() * 2 - 1, 6);
        }

        return '['.implode(',', $values).']';
    }
}
