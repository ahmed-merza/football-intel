<?php

declare(strict_types=1);

use App\Models\Player;
use App\Models\PlayerRecord;
use App\Models\RecordCategory;
use App\Models\RecordMetric;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Performance budget: timeline query for 12-month player must stay
 * under 500ms. The composite index `player_records_timeline_idx` on
 * (player_id, category_id, record_date) is what keeps this fast. If you ever
 * see this test start to fail, check that the index is still present before
 * increasing the budget.
 */
it('loads a 12-month timeline (60 records + 96 metrics) in under 500ms', function (): void {
    $player = Player::factory()->create();
    $categories = RecordCategory::all()->keyBy('slug');

    // 12 months × (1 blood + 1 inbody + 2 GPS + 1 match) = 60 records
    for ($monthsAgo = 11; $monthsAgo >= 0; $monthsAgo--) {
        $date = CarbonImmutable::now()->subMonths($monthsAgo);

        $blood = PlayerRecord::factory()->bloodTest()->create([
            'player_id' => $player->id,
            'record_date' => $date->toDateString(),
        ]);
        $inbody = PlayerRecord::factory()->inbody()->create([
            'player_id' => $player->id,
            'record_date' => $date->addDay()->toDateString(),
        ]);
        PlayerRecord::factory()->gpsWearable()->create([
            'player_id' => $player->id,
            'record_date' => $date->addDays(3)->toDateString(),
        ]);
        PlayerRecord::factory()->gpsWearable()->create([
            'player_id' => $player->id,
            'record_date' => $date->addDays(10)->toDateString(),
        ]);
        PlayerRecord::factory()->bloodTest()->create([
            'player_id' => $player->id,
            'category_id' => $categories[RecordCategory::MATCH_ACTIVITY]->id,
            'record_date' => $date->addDays(14)->toDateString(),
        ]);

        // Fan out 5 blood + 3 inbody metric rows per month → 96 rows / 12 months
        RecordMetric::factory()->count(5)->create([
            'record_id' => $blood->id,
            'player_id' => $player->id,
            'category_id' => $blood->category_id,
            'record_date' => $blood->record_date,
        ]);
        RecordMetric::factory()->bodyFat()->count(3)->create([
            'record_id' => $inbody->id,
            'player_id' => $player->id,
            'category_id' => $inbody->category_id,
            'record_date' => $inbody->record_date,
        ]);
    }

    // Warm the connection once so we're measuring query time, not TCP setup
    PlayerRecord::where('player_id', $player->id)->count();

    $start = microtime(true);

    $timeline = PlayerRecord::forPlayer($player->id)
        ->with('category')
        ->orderByDesc('record_date')
        ->limit(50)
        ->get();

    $elapsedMs = (microtime(true) - $start) * 1000;

    expect($timeline)->toHaveCount(50)
        ->and($elapsedMs)->toBeLessThan(500.0);
});

it('loads a 12-month single-metric trend chart in under 200ms', function (): void {
    $player = Player::factory()->create();

    // 12 monthly haemoglobin readings — the hot query for a trend chart
    for ($monthsAgo = 11; $monthsAgo >= 0; $monthsAgo--) {
        $date = CarbonImmutable::now()->subMonths($monthsAgo);
        $record = PlayerRecord::factory()->bloodTest()->create([
            'player_id' => $player->id,
            'record_date' => $date->toDateString(),
        ]);
        RecordMetric::factory()->create([
            'record_id' => $record->id,
            'player_id' => $player->id,
            'category_id' => $record->category_id,
            'record_date' => $record->record_date,
            'metric_key' => 'hb_g_dl',
        ]);
    }

    RecordMetric::count(); // warm connection

    $start = microtime(true);

    $trend = RecordMetric::forPlayer($player->id)
        ->key('hb_g_dl')
        ->orderBy('record_date')
        ->get(['record_date', 'metric_value', 'flag']);

    $elapsedMs = (microtime(true) - $start) * 1000;

    expect($trend)->toHaveCount(12)
        ->and($elapsedMs)->toBeLessThan(200.0);
});
