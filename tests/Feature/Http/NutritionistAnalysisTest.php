<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Jobs\GenerateNutritionistAnalysisJob;
use App\Models\NutritionistAnalysis;
use App\Models\Player;
use App\Models\PlayerRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class NutritionistAnalysisTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_creates_pending_analysis_and_dispatches_job_when_eligible(): void
    {
        Bus::fake();
        $admin = User::factory()->create();
        $player = Player::factory()->create();
        PlayerRecord::factory()->bloodTest()->create(['player_id' => $player->id]);
        PlayerRecord::factory()->inbody()->create(['player_id' => $player->id]);

        $this->actingAs($admin)
            ->post("/players/{$player->id}/nutritionist-analyses")
            ->assertRedirect();

        $analysis = NutritionistAnalysis::firstOrFail();
        $this->assertSame($player->id, $analysis->player_id);
        $this->assertSame(NutritionistAnalysis::STATUS_PENDING, $analysis->status);
        $this->assertSame($admin->id, $analysis->generated_by);
        $this->assertNull($analysis->payload);

        Bus::assertDispatched(
            GenerateNutritionistAnalysisJob::class,
            fn ($job) => $job->analysisId === $analysis->id,
        );
    }

    public function test_store_rejects_when_player_has_no_blood_test(): void
    {
        Bus::fake();
        $admin = User::factory()->create();
        $player = Player::factory()->create();
        PlayerRecord::factory()->inbody()->create(['player_id' => $player->id]);

        $this->actingAs($admin)
            ->post("/players/{$player->id}/nutritionist-analyses")
            ->assertRedirect();

        $this->assertSame(0, NutritionistAnalysis::count());
        Bus::assertNotDispatched(GenerateNutritionistAnalysisJob::class);
    }

    public function test_store_rejects_when_player_has_no_body_composition_record(): void
    {
        Bus::fake();
        $admin = User::factory()->create();
        $player = Player::factory()->create();
        PlayerRecord::factory()->bloodTest()->create(['player_id' => $player->id]);

        $this->actingAs($admin)
            ->post("/players/{$player->id}/nutritionist-analyses")
            ->assertRedirect();

        $this->assertSame(0, NutritionistAnalysis::count());
        Bus::assertNotDispatched(GenerateNutritionistAnalysisJob::class);
    }

    public function test_store_requires_authentication(): void
    {
        $player = Player::factory()->create();

        $this->post("/players/{$player->id}/nutritionist-analyses")
            ->assertRedirect('/login');
    }

    public function test_failed_hook_marks_pending_analysis_as_failed(): void
    {
        $analysis = NutritionistAnalysis::factory()->pending()->create();

        $job = new GenerateNutritionistAnalysisJob($analysis->id);
        // Use a recognised pattern so humanise() returns its mapped
        // sentence — proves the hook stores the friendly version, not
        // the raw exception. (Generic exceptions flow through too;
        // covered separately in AgentErrorMessageTest.)
        $job->failed(new \RuntimeException('cURL error 28: Operation timed out after 300002 ms'));

        $fresh = $analysis->fresh();
        $this->assertSame(NutritionistAnalysis::STATUS_FAILED, $fresh->status);
        $this->assertStringContainsString('timed out', $fresh->error ?? '');
        $this->assertStringNotContainsString('cURL', $fresh->error ?? '');
    }

    public function test_failed_hook_does_not_overwrite_completed_analyses(): void
    {
        $analysis = NutritionistAnalysis::factory()->create([
            'status' => NutritionistAnalysis::STATUS_COMPLETED,
        ]);

        $job = new GenerateNutritionistAnalysisJob($analysis->id);
        $job->failed(new \RuntimeException('late timeout'));

        $this->assertSame(
            NutritionistAnalysis::STATUS_COMPLETED,
            $analysis->fresh()->status,
        );
    }
}
