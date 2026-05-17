<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Jobs\GenerateNutritionistAnalysisJob;
use App\Models\NutritionistAnalysis;
use App\Models\Player;
use App\Models\PlayerRecord;
use App\Models\User;
use App\Services\Ai\AgentRouter;
use App\Services\Ai\CallbackPendingException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Ai\Contracts\Agent;
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

    public function test_retry_resets_failed_analysis_and_redispatches_job(): void
    {
        Bus::fake();
        $admin = User::factory()->create();
        $player = Player::factory()->create();
        $analysis = NutritionistAnalysis::factory()->failed()->create([
            'player_id' => $player->id,
        ]);

        $this->actingAs($admin)
            ->post("/nutritionist-analyses/{$analysis->id}/retry")
            ->assertRedirect();

        $fresh = $analysis->fresh();
        $this->assertSame(NutritionistAnalysis::STATUS_PENDING, $fresh->status);
        $this->assertNull($fresh->error);

        Bus::assertDispatched(
            GenerateNutritionistAnalysisJob::class,
            fn ($job) => $job->analysisId === $analysis->id,
        );
    }

    public function test_retry_refuses_pending_analysis(): void
    {
        // Already in flight — re-dispatching would create a duplicate
        // worker for the same row.
        Bus::fake();
        $admin = User::factory()->create();
        $analysis = NutritionistAnalysis::factory()->pending()->create();

        $this->actingAs($admin)
            ->post("/nutritionist-analyses/{$analysis->id}/retry")
            ->assertRedirect();

        $this->assertSame(NutritionistAnalysis::STATUS_PENDING, $analysis->fresh()->status);
        Bus::assertNotDispatched(GenerateNutritionistAnalysisJob::class);
    }

    public function test_retry_refuses_completed_analysis(): void
    {
        // Protect known-good results — retry must not clobber them.
        Bus::fake();
        $admin = User::factory()->create();
        $analysis = NutritionistAnalysis::factory()->create([
            'status' => NutritionistAnalysis::STATUS_COMPLETED,
            'summary_text' => 'Already done',
        ]);

        $this->actingAs($admin)
            ->post("/nutritionist-analyses/{$analysis->id}/retry")
            ->assertRedirect();

        $this->assertSame(NutritionistAnalysis::STATUS_COMPLETED, $analysis->fresh()->status);
        $this->assertSame('Already done', $analysis->fresh()->summary_text);
        Bus::assertNotDispatched(GenerateNutritionistAnalysisJob::class);
    }

    public function test_retry_requires_authentication(): void
    {
        $analysis = NutritionistAnalysis::factory()->failed()->create();

        $this->post("/nutritionist-analyses/{$analysis->id}/retry")
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

    public function test_job_resets_failed_analysis_to_pending_so_ui_stops_showing_stale_error(): void
    {
        // CLI `queue:retry` on a previously-failed analysis must not let
        // the UI keep showing the old error while the new attempt is in
        // flight — that's the "still says Failed during retry" bug.
        $player = Player::factory()->create();
        PlayerRecord::factory()->bloodTest()->create(['player_id' => $player->id]);
        PlayerRecord::factory()->inbody()->create(['player_id' => $player->id]);
        $analysis = NutritionistAnalysis::factory()->failed()->create([
            'player_id' => $player->id,
        ]);

        $router = new class extends AgentRouter
        {
            public function __construct() {}

            public function send(Agent $agent, string $userPrompt, array $context = []): array
            {
                // Throw before any AI call: by the time the router runs,
                // the job has already had its chance to reset the row.
                throw new CallbackPendingException('corr-uuid');
            }
        };

        (new GenerateNutritionistAnalysisJob($analysis->id))->handle($router);

        $fresh = $analysis->fresh();
        $this->assertSame(NutritionistAnalysis::STATUS_PENDING, $fresh->status);
        $this->assertNull($fresh->error);
    }

    public function test_job_skips_already_completed_analysis_to_protect_late_callback_result(): void
    {
        // If a callback landed (or a previous run finished) between the
        // job dispatch and pickup, retrying must not overwrite the good
        // result with a fresh AI call.
        $player = Player::factory()->create();
        PlayerRecord::factory()->bloodTest()->create(['player_id' => $player->id]);
        PlayerRecord::factory()->inbody()->create(['player_id' => $player->id]);
        $analysis = NutritionistAnalysis::factory()->create([
            'player_id' => $player->id,
            'status' => NutritionistAnalysis::STATUS_COMPLETED,
            'summary_text' => 'Already done',
        ]);

        $router = new class extends AgentRouter
        {
            public function __construct() {}

            public function send(Agent $agent, string $userPrompt, array $context = []): array
            {
                throw new \LogicException('router must not be called for an already-completed analysis');
            }
        };

        (new GenerateNutritionistAnalysisJob($analysis->id))->handle($router);

        $this->assertSame(NutritionistAnalysis::STATUS_COMPLETED, $analysis->fresh()->status);
        $this->assertSame('Already done', $analysis->fresh()->summary_text);
    }

    public function test_job_leaves_analysis_pending_when_router_throws_callback_pending(): void
    {
        // Mirrors ExtractStructuredDataJob's async-insurance handling:
        // when the sync call times out we must NOT mark the analysis as
        // failed — n8n is still running and will hit our callback when
        // done. Job exits cleanly so Horizon doesn't retry.
        $player = Player::factory()->create();
        PlayerRecord::factory()->bloodTest()->create(['player_id' => $player->id]);
        PlayerRecord::factory()->inbody()->create(['player_id' => $player->id]);
        $analysis = NutritionistAnalysis::factory()->pending()->create([
            'player_id' => $player->id,
        ]);

        $router = new class extends AgentRouter
        {
            public function __construct() {}

            public function send(Agent $agent, string $userPrompt, array $context = []): array
            {
                throw new CallbackPendingException('corr-uuid');
            }
        };

        (new GenerateNutritionistAnalysisJob($analysis->id))->handle($router);

        $fresh = $analysis->fresh();
        $this->assertSame(NutritionistAnalysis::STATUS_PENDING, $fresh->status);
        $this->assertNull($fresh->error);
        $this->assertNull($fresh->generated_at);
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
