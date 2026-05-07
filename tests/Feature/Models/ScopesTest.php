<?php

declare(strict_types=1);

use App\Models\Alert;
use App\Models\Player;
use App\Models\PlayerRecord;
use App\Models\RecordCategory;
use App\Models\RecordMetric;
use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('Player::active filters out inactive and archived rows', function (): void {
    Player::factory()->count(3)->create();
    Player::factory()->inactive()->create();
    Player::factory()->archived()->create();

    expect(Player::active()->count())->toBe(3)
        ->and(Player::count())->toBe(5);
});

it('Alert::open filters to unacknowledged alerts', function (): void {
    $player = Player::factory()->create();
    Alert::factory()->count(2)->create(['player_id' => $player->id]);
    Alert::factory()->acknowledged()->create(['player_id' => $player->id]);

    expect(Alert::open()->count())->toBe(2)
        ->and(Alert::count())->toBe(3);
});

it('Alert::severity filters by level', function (): void {
    $player = Player::factory()->create();
    Alert::factory()->critical()->create(['player_id' => $player->id]);
    Alert::factory()->create(['player_id' => $player->id]);
    Alert::factory()->info()->create(['player_id' => $player->id]);

    expect(Alert::severity(Alert::SEVERITY_CRITICAL)->count())->toBe(1)
        ->and(Alert::severity(Alert::SEVERITY_WARN)->count())->toBe(1)
        ->and(Alert::severity(Alert::SEVERITY_INFO)->count())->toBe(1);
});

it('RecordMetric::forPlayer + key narrow to trend-chart-shaped data', function (): void {
    $target = Player::factory()->create();
    $other = Player::factory()->create();
    $record = PlayerRecord::factory()->bloodTest()->create(['player_id' => $target->id]);
    $otherRecord = PlayerRecord::factory()->bloodTest()->create(['player_id' => $other->id]);

    RecordMetric::factory()->ferritin(45.0)->create([
        'record_id' => $record->id, 'player_id' => $target->id, 'record_date' => $record->record_date,
    ]);
    RecordMetric::factory()->vitaminD(35.0)->create([
        'record_id' => $record->id, 'player_id' => $target->id, 'record_date' => $record->record_date,
    ]);
    RecordMetric::factory()->ferritin(50.0)->create([
        'record_id' => $otherRecord->id, 'player_id' => $other->id, 'record_date' => $otherRecord->record_date,
    ]);

    expect(RecordMetric::forPlayer($target->id)->count())->toBe(2)
        ->and(RecordMetric::forPlayer($target->id)->key('ferritin_ng_ml')->count())->toBe(1)
        ->and(RecordMetric::key('ferritin_ng_ml')->count())->toBe(2);
});

it('PlayerRecord::forPlayer + ofCategory narrow to category timeline', function (): void {
    $player = Player::factory()->create();
    PlayerRecord::factory()->bloodTest()->count(2)->create(['player_id' => $player->id]);
    PlayerRecord::factory()->inbody()->count(3)->create(['player_id' => $player->id]);
    PlayerRecord::factory()->bloodTest()->create(); // different player

    expect(PlayerRecord::forPlayer($player->id)->count())->toBe(5)
        ->and(PlayerRecord::forPlayer($player->id)->ofCategory(RecordCategory::BLOOD_TEST)->count())->toBe(2)
        ->and(PlayerRecord::forPlayer($player->id)->ofCategory(RecordCategory::INBODY)->count())->toBe(3);
});

it('Report::type filters player vs team reports', function (): void {
    Report::factory()->count(2)->create();
    Report::factory()->team()->create();

    expect(Report::type(Report::TYPE_PLAYER)->count())->toBe(2)
        ->and(Report::type(Report::TYPE_TEAM)->count())->toBe(1);
});

it('exposes age and bmi accessors on Player', function (): void {
    $player = Player::factory()->create([
        'date_of_birth' => now()->subYears(25)->subMonths(3),
        'height_cm' => 180,
        'weight_kg' => 75.0,
    ]);

    expect($player->age)->toBe(25)
        ->and($player->bmi)->toBe(23.1);
});

it('returns null age/bmi when the source columns are blank', function (): void {
    $player = Player::factory()->create([
        'date_of_birth' => null,
        'height_cm' => null,
        'weight_kg' => null,
    ]);

    expect($player->age)->toBeNull()
        ->and($player->bmi)->toBeNull();
});
