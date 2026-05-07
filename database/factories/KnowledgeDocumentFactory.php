<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\KnowledgeDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<KnowledgeDocument>
 */
class KnowledgeDocumentFactory extends Factory
{
    protected $model = KnowledgeDocument::class;

    public function definition(): array
    {
        $title = $this->faker->randomElement([
            'Supplement Guidelines v3',
            'Match-day Nutrition Protocol',
            'Hydration & Electrolyte Plan',
            'Recovery Nutrition Guide',
            'Iron Deficiency Management',
        ]);

        return [
            'title' => $title,
            'source_type' => $this->faker->randomElement([
                KnowledgeDocument::SOURCE_NUTRITION_PLAN,
                KnowledgeDocument::SOURCE_SUPPLEMENT_GUIDE,
                KnowledgeDocument::SOURCE_PROTOCOL,
                KnowledgeDocument::SOURCE_NOTE,
            ]),
            'storage_disk' => 'local',
            'storage_path' => 'knowledge/'.Str::uuid()->toString().'.pdf',
            'language' => 'en',
            'metadata' => [
                'author' => 'Right Calories Sports Nutrition',
                'published_at' => $this->faker->date(),
                'tags' => ['football', 'nutrition', 'bahrain'],
            ],
            'uploaded_by' => User::factory(),
        ];
    }

    public function supplementGuide(): static
    {
        return $this->state(fn (): array => [
            'source_type' => KnowledgeDocument::SOURCE_SUPPLEMENT_GUIDE,
            'title' => 'Supplement Guidelines — Bahrain National Team',
        ]);
    }

    public function nutritionPlan(): static
    {
        return $this->state(fn (): array => [
            'source_type' => KnowledgeDocument::SOURCE_NUTRITION_PLAN,
        ]);
    }
}
