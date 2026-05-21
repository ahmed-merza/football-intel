<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-player row inside a {@see MatchReport}. Each player who appears in
 * the fixture gets one — both Bahrain and opposition. Opposition rows
 * carry team_side='opponent' and player_id=NULL (kept for team-level
 * stats + head-to-head context, never offered for resolution).
 *
 * For resolved Bahrain rows a matching {@see PlayerRecord} is also
 * created (category=match_performance), reachable via player_record_id —
 * that's how the existing timeline / metric-fanner / Nutritionist
 * Assistant context paths pick up match data without new wiring.
 *
 * @property int $id
 * @property int $match_report_id
 * @property string $team_side
 * @property int|null $player_id
 * @property string|null $reported_name
 * @property int|null $jersey_number
 * @property string|null $match_position
 * @property string $appearance
 * @property int|null $minute_on
 * @property int|null $minute_off
 * @property int|null $minutes_played
 * @property float|null $rating
 * @property int $goals
 * @property int $assists
 * @property int $shots
 * @property int $shots_on_target
 * @property int $shots_blocked
 * @property int $shots_missed
 * @property int $shots_inside_pa
 * @property int $shots_outside_pa
 * @property int $offsides
 * @property int $freekicks_taken
 * @property int $corners_taken
 * @property int $throw_ins
 * @property int $take_ons_attempted
 * @property int $take_ons_succeeded
 * @property int $passes_total
 * @property int $passes_succeeded
 * @property float|null $pass_accuracy_pct
 * @property int $key_passes
 * @property int $crosses_attempted
 * @property int $crosses_succeeded
 * @property int $controls_under_pressure
 * @property array<string, mixed>|null $pass_breakdown
 * @property int $tackles_attempted
 * @property int $tackles_succeeded
 * @property int $aerial_duels_total
 * @property int $aerial_duels_won
 * @property int $ground_duels_total
 * @property int $ground_duels_won
 * @property int $interceptions
 * @property int $clearances
 * @property int $interventions
 * @property int $recoveries
 * @property int $blocks
 * @property int $mistakes
 * @property int $fouls_committed
 * @property int $fouls_won
 * @property int $yellow_cards
 * @property int $red_cards
 * @property int|null $goals_conceded
 * @property int|null $catches
 * @property int|null $parries
 * @property int|null $goal_kicks_attempted
 * @property int|null $goal_kicks_succeeded
 * @property int|null $aerial_clearances_attempted
 * @property int|null $aerial_clearances_succeeded
 * @property array<string, mixed>|null $raw_extracted
 * @property int|null $player_record_id
 */
class MatchPerformance extends Model
{
    public const TEAM_SIDE_BAHRAIN = 'bahrain';

    public const TEAM_SIDE_OPPONENT = 'opponent';

    public const APPEARANCE_STARTER = 'starter';

    public const APPEARANCE_SUB = 'sub';

    public const APPEARANCE_UNUSED = 'unused';

    protected $fillable = [
        'match_report_id', 'team_side', 'player_id', 'reported_name',
        'jersey_number', 'match_position', 'appearance',
        'minute_on', 'minute_off', 'minutes_played', 'rating',

        'goals', 'assists', 'shots', 'shots_on_target', 'shots_blocked', 'shots_missed',
        'shots_inside_pa', 'shots_outside_pa', 'offsides',
        'freekicks_taken', 'corners_taken', 'throw_ins',
        'take_ons_attempted', 'take_ons_succeeded',

        'passes_total', 'passes_succeeded', 'pass_accuracy_pct', 'key_passes',
        'crosses_attempted', 'crosses_succeeded', 'controls_under_pressure',
        'pass_breakdown',

        'tackles_attempted', 'tackles_succeeded',
        'aerial_duels_total', 'aerial_duels_won',
        'ground_duels_total', 'ground_duels_won',
        'interceptions', 'clearances', 'interventions', 'recoveries', 'blocks', 'mistakes',

        'fouls_committed', 'fouls_won', 'yellow_cards', 'red_cards',

        'goals_conceded', 'catches', 'parries',
        'goal_kicks_attempted', 'goal_kicks_succeeded',
        'aerial_clearances_attempted', 'aerial_clearances_succeeded',

        'raw_extracted', 'player_record_id',
    ];

    protected $casts = [
        'rating' => 'decimal:1',
        'pass_accuracy_pct' => 'decimal:2',
        'raw_extracted' => 'array',
        'pass_breakdown' => 'array',
    ];

    /** @return BelongsTo<MatchReport, $this> */
    public function matchReport(): BelongsTo
    {
        return $this->belongsTo(MatchReport::class);
    }

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** @return BelongsTo<PlayerRecord, $this> */
    public function playerRecord(): BelongsTo
    {
        return $this->belongsTo(PlayerRecord::class, 'player_record_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeBahrain(Builder $query): Builder
    {
        return $query->where('team_side', self::TEAM_SIDE_BAHRAIN);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->where('team_side', self::TEAM_SIDE_BAHRAIN)->whereNull('player_id');
    }
}
