<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Alert;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertsTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_shows_open_alerts_by_default_and_supports_filter_tabs(): void
    {
        $admin = User::factory()->create();
        $player = Player::factory()->create();
        Alert::factory()->create(['player_id' => $player->id]);            // open
        Alert::factory()->acknowledged()->create(['player_id' => $player->id]); // ack'd

        $response = $this->actingAs($admin)->get('/alerts');
        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertCount(1, $props['alerts']);
        $this->assertSame(1, $props['counts']['open']);
        $this->assertSame(1, $props['counts']['acknowledged']);

        $this->actingAs($admin)
            ->get('/alerts?filter=acknowledged')
            ->assertOk();
    }

    public function test_acknowledge_flips_alert_with_user_and_timestamp(): void
    {
        $admin = User::factory()->create();
        $alert = Alert::factory()->create();
        $this->assertNull($alert->acknowledged_at);

        $this->actingAs($admin)
            ->post("/alerts/{$alert->id}/acknowledge")
            ->assertRedirect();

        $alert->refresh();
        $this->assertNotNull($alert->acknowledged_at);
        $this->assertSame($admin->id, $alert->acknowledged_by);
    }

    public function test_acknowledge_is_idempotent(): void
    {
        $admin = User::factory()->create();
        $alert = Alert::factory()->acknowledged()->create([
            'acknowledged_by' => $admin->id,
        ]);
        $original = $alert->fresh()->acknowledged_at?->getTimestamp();

        $this->actingAs($admin)
            ->post("/alerts/{$alert->id}/acknowledge")
            ->assertRedirect();

        $this->assertSame($original, $alert->fresh()->acknowledged_at?->getTimestamp());
    }

    public function test_unacknowledge_reopens_an_acknowledged_alert(): void
    {
        $admin = User::factory()->create();
        $alert = Alert::factory()->acknowledged()->create([
            'acknowledged_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->delete("/alerts/{$alert->id}/acknowledge")
            ->assertRedirect();

        $alert->refresh();
        $this->assertNull($alert->acknowledged_at);
        $this->assertNull($alert->acknowledged_by);
    }

    public function test_unacknowledge_is_idempotent_on_open_alerts(): void
    {
        $admin = User::factory()->create();
        $alert = Alert::factory()->create();

        $this->actingAs($admin)
            ->delete("/alerts/{$alert->id}/acknowledge")
            ->assertRedirect();

        $this->assertNull($alert->fresh()->acknowledged_at);
    }

    public function test_alerts_require_authentication(): void
    {
        $alert = Alert::factory()->create();
        $this->get('/alerts')->assertRedirect('/login');
        $this->post("/alerts/{$alert->id}/acknowledge")->assertRedirect('/login');
        $this->delete("/alerts/{$alert->id}/acknowledge")->assertRedirect('/login');
    }
}
