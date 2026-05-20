<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Jobs\ExtractMatchReportJob;
use App\Jobs\ExtractTextFromAttachmentJob;
use App\Models\MatchPerformance;
use App\Models\MatchReport;
use App\Models\PendingExtraction;
use App\Models\Player;
use App\Models\PlayerRecord;
use App\Models\RecordCategory;
use App\Models\RecordMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the post-upload routes: preview / apply / retry / destroy, and
 * the n8n async-callback path that closes the loop on a sync timeout.
 */
class MatchReportFlowTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'flow-test-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ai.providers.n8n.callback_secret' => self::SECRET,
        ]);
    }

    // -- Preview ---------------------------------------------------------

    public function test_preview_shows_processing_state_while_extracting(): void
    {
        $admin = User::factory()->create();
        $report = MatchReport::factory()->pending()->create();

        $this->actingAs($admin)
            ->get("/matches/{$report->id}/preview")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('matches/preview')
                ->where('match.status', MatchReport::STATUS_PENDING)
                ->where('performances', [])
            );
    }

    public function test_preview_surfaces_extraction_error_for_failed_reports(): void
    {
        $admin = User::factory()->create();
        $report = MatchReport::factory()->failed()->create();

        $this->actingAs($admin)
            ->get("/matches/{$report->id}/preview")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('match.status', MatchReport::STATUS_FAILED)
                ->where('match.extraction_error', $report->extraction_error)
            );
    }

    public function test_preview_returns_performances_with_suggestions_when_extracted(): void
    {
        $admin = User::factory()->create();
        $matchingPlayer = Player::factory()->create(['full_name' => 'Mohammed Alyaqoob']);
        $report = MatchReport::factory()->create();

        $this->actingAs($admin)
            ->get("/matches/{$report->id}/preview")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('match.status', MatchReport::STATUS_EXTRACTED)
                ->has('performances', 3)
                ->where('performances.0.reported_name', 'Mohammed Alyaqoob')
                ->where('performances.0.team_side', MatchPerformance::TEAM_SIDE_BAHRAIN)
                ->has('performances.0.suggestions', fn ($s) => $s
                    ->where('0.player_id', $matchingPlayer->id)
                    ->etc(),
                )
                ->has('active_players'),
            );
    }

    public function test_preview_redirects_to_show_when_already_applied(): void
    {
        $admin = User::factory()->create();
        $report = MatchReport::factory()->applied()->create();

        $this->actingAs($admin)
            ->get("/matches/{$report->id}/preview")
            ->assertRedirect("/matches/{$report->id}");
    }

    // -- Apply -----------------------------------------------------------

    public function test_apply_commits_performances_and_player_records_atomically(): void
    {
        $admin = User::factory()->create();
        $player = Player::factory()->create(['full_name' => 'Mohammed Alyaqoob']);
        $report = MatchReport::factory()->create();

        $response = $this->actingAs($admin)->post("/matches/{$report->id}/apply", [
            'resolutions' => [
                0 => ['type' => 'existing', 'player_id' => $player->id],
                1 => ['type' => 'skip'],
            ],
        ]);

        $response->assertRedirect("/matches/{$report->id}");

        $report->refresh();
        $this->assertSame(MatchReport::STATUS_APPLIED, $report->status);
        $this->assertSame(3, MatchPerformance::where('match_report_id', $report->id)->count());
        $this->assertSame(
            1,
            PlayerRecord::query()
                ->where('player_id', $player->id)
                ->whereHas('category', fn ($q) => $q->where('slug', RecordCategory::MATCH_PERFORMANCE))
                ->count(),
        );
        $this->assertGreaterThan(0, RecordMetric::where('player_id', $player->id)->count());
    }

    public function test_apply_supports_inline_player_creation(): void
    {
        $admin = User::factory()->create();
        $report = MatchReport::factory()->create();

        $this->actingAs($admin)->post("/matches/{$report->id}/apply", [
            'resolutions' => [
                1 => [
                    'type' => 'new',
                    'data' => [
                        'full_name' => 'Hassan Al-Sayed',
                        'position' => 'DF',
                        'nationality' => 'Bahraini',
                    ],
                ],
            ],
        ])->assertRedirect();

        $newPlayer = Player::where('full_name', 'Hassan Al-Sayed')->firstOrFail();
        $this->assertSame('Bahraini', $newPlayer->nationality);
        $this->assertSame(
            $newPlayer->id,
            MatchPerformance::where('match_report_id', $report->id)
                ->where('jersey_number', 5)
                ->value('player_id'),
        );
    }

    public function test_apply_rejects_when_report_not_in_extracted_state(): void
    {
        $admin = User::factory()->create();
        $report = MatchReport::factory()->pending()->create();
        $player = Player::factory()->create();

        $this->actingAs($admin)->post("/matches/{$report->id}/apply", [
            'resolutions' => [0 => ['type' => 'existing', 'player_id' => $player->id]],
        ])->assertRedirect();

        $this->assertSame(0, MatchPerformance::where('match_report_id', $report->id)->count());
        $this->assertSame(MatchReport::STATUS_PENDING, $report->fresh()->status);
    }

    public function test_apply_validates_resolution_payload(): void
    {
        $admin = User::factory()->create();
        $report = MatchReport::factory()->create();

        // Missing required `type` field.
        $this->actingAs($admin)->post("/matches/{$report->id}/apply", [
            'resolutions' => [0 => ['player_id' => 1]],
        ])->assertSessionHasErrors('resolutions.0.type');

        // type=existing but no player_id.
        $this->actingAs($admin)->post("/matches/{$report->id}/apply", [
            'resolutions' => [0 => ['type' => 'existing']],
        ])->assertSessionHasErrors('resolutions.0.player_id');

        // type=new but no data.
        $this->actingAs($admin)->post("/matches/{$report->id}/apply", [
            'resolutions' => [0 => ['type' => 'new']],
        ])->assertSessionHasErrors('resolutions.0.data');
    }

    public function test_apply_can_be_re_run_idempotently_after_admin_edits_resolutions(): void
    {
        $admin = User::factory()->create();
        $playerA = Player::factory()->create();
        $playerB = Player::factory()->create();
        $report = MatchReport::factory()->create();

        $this->actingAs($admin)->post("/matches/{$report->id}/apply", [
            'resolutions' => [0 => ['type' => 'existing', 'player_id' => $playerA->id]],
        ])->assertRedirect();

        // Admin notices the wrong player was picked, goes back to preview,
        // re-applies. The applier wipes the prior fan-out so player B picks
        // it up cleanly and player A's data is gone. We need to bounce the
        // report back to `extracted` to allow the re-apply (the controller
        // gates on that status).
        $report->update(['status' => MatchReport::STATUS_EXTRACTED]);

        $this->actingAs($admin)->post("/matches/{$report->id}/apply", [
            'resolutions' => [0 => ['type' => 'existing', 'player_id' => $playerB->id]],
        ])->assertRedirect();

        $this->assertSame(0, PlayerRecord::where('player_id', $playerA->id)->count());
        $this->assertSame(1, PlayerRecord::where('player_id', $playerB->id)->count());
    }

    // -- Retry -----------------------------------------------------------

    public function test_retry_re_dispatches_extraction_for_failed_report(): void
    {
        Bus::fake();
        $admin = User::factory()->create();
        $report = MatchReport::factory()->failed()->create();

        $this->actingAs($admin)
            ->post("/matches/{$report->id}/retry")
            ->assertRedirect();

        $report->refresh();
        $this->assertSame(MatchReport::STATUS_PENDING, $report->status);
        $this->assertNull($report->extraction_error);

        Bus::assertChained([
            ExtractTextFromAttachmentJob::class,
            ExtractMatchReportJob::class,
        ]);
    }

    public function test_retry_works_on_awaiting_callback_reports(): void
    {
        Bus::fake();
        $admin = User::factory()->create();
        $report = MatchReport::factory()->awaitingCallback()->create();

        $this->actingAs($admin)
            ->post("/matches/{$report->id}/retry")
            ->assertRedirect();

        $this->assertSame(MatchReport::STATUS_PENDING, $report->fresh()->status);
    }

    public function test_retry_refuses_on_applied_reports(): void
    {
        Bus::fake();
        $admin = User::factory()->create();
        $report = MatchReport::factory()->applied()->create();

        $this->actingAs($admin)
            ->post("/matches/{$report->id}/retry")
            ->assertRedirect();

        $this->assertSame(MatchReport::STATUS_APPLIED, $report->fresh()->status);
        Bus::assertNothingDispatched();
    }

    // -- N8n callback (match-report kind) -------------------------------

    public function test_n8n_callback_for_match_report_kind_promotes_to_extracted(): void
    {
        $report = MatchReport::factory()->awaitingCallback()->create();

        $pending = PendingExtraction::create([
            'correlation_id' => (string) Str::uuid(),
            'match_report_id' => $report->id,
            'kind' => PendingExtraction::KIND_MATCH_REPORT,
            'status' => PendingExtraction::STATUS_PENDING,
            'request' => ['has_schema' => true],
            'expires_at' => Carbon::now()->addMinutes(15),
        ]);

        $extractorPayload = [
            'competition' => 'AGCFF U20 Arab Gulf Cup',
            'stage' => 'Group B Round 1',
            'match_date' => '2025-08-29',
            'home_team_name' => 'Iraq U20',
            'away_team_name' => 'Bahrain U20',
            'home_score' => 1,
            'away_score' => 0,
            'performances' => [
                [
                    'team_side' => 'away',
                    'reported_name' => 'Mohammed Alyaqoob',
                    'jersey_number' => 6,
                    'match_position' => 'CB',
                    'appearance' => 'starter',
                    'minutes_played' => 90,
                    'rating' => 6.9,
                ],
            ],
        ];

        $this->postJson('/webhooks/n8n/extraction', [
            'correlation_id' => $pending->correlation_id,
            'result' => [['code' => 0, 'stdout' => json_encode($extractorPayload), 'stderr' => '']],
        ], ['X-Callback-Secret' => self::SECRET])
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        $report->refresh();
        $this->assertSame(MatchReport::STATUS_EXTRACTED, $report->status);
        $this->assertSame(MatchReport::BAHRAIN_SIDE_AWAY, $report->bahrain_side);
        $this->assertSame('2025-08-29', $report->match_date?->toDateString());
        $this->assertIsArray($report->raw_extracted);

        $pending->refresh();
        $this->assertSame(PendingExtraction::STATUS_COMPLETED, $pending->status);
    }

    public function test_n8n_callback_skips_when_report_no_longer_awaiting_callback(): void
    {
        // Sync response beat the callback to the punch — the report is
        // already in `extracted`. Callback must not clobber the fresher state.
        $report = MatchReport::factory()->create([
            'status' => MatchReport::STATUS_EXTRACTED,
            'competition' => 'Original sync extraction',
        ]);

        $pending = PendingExtraction::create([
            'correlation_id' => (string) Str::uuid(),
            'match_report_id' => $report->id,
            'kind' => PendingExtraction::KIND_MATCH_REPORT,
            'status' => PendingExtraction::STATUS_PENDING,
            'request' => ['has_schema' => true],
            'expires_at' => Carbon::now()->addMinutes(15),
        ]);

        $this->postJson('/webhooks/n8n/extraction', [
            'correlation_id' => $pending->correlation_id,
            'result' => [['code' => 0, 'stdout' => '{"competition": "Late callback override"}', 'stderr' => '']],
        ], ['X-Callback-Secret' => self::SECRET])
            ->assertOk();

        $this->assertSame('Original sync extraction', $report->fresh()->competition);
    }

    // -- Show + destroy --------------------------------------------------

    public function test_show_renders_applied_match_with_resolved_performances(): void
    {
        $admin = User::factory()->create();
        $player = Player::factory()->create(['full_name' => 'Mohammed Alyaqoob']);
        $report = MatchReport::factory()->create();

        // Apply first so we have data to show.
        $this->actingAs($admin)->post("/matches/{$report->id}/apply", [
            'resolutions' => [0 => ['type' => 'existing', 'player_id' => $player->id]],
        ])->assertRedirect();

        $this->actingAs($admin)
            ->get("/matches/{$report->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('matches/show')
                ->where('match.status', MatchReport::STATUS_APPLIED)
                ->has('performances', 3)
            );
    }

    public function test_destroy_soft_deletes_the_report(): void
    {
        $admin = User::factory()->create();
        $report = MatchReport::factory()->applied()->create();

        $this->actingAs($admin)
            ->delete("/matches/{$report->id}")
            ->assertRedirect('/matches');

        $this->assertSoftDeleted($report);
    }
}
