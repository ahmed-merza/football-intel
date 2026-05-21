<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Single goalkeeper save event extracted from the AGCFF "Goalkeeper"
 * section of a match-report PDF. Phase-2.
 *
 * Sibling of {@see MatchShotEvent} — same shape, different source
 * section. Join against match_performances on
 * (match_report_id, team_side, jersey_number) to resolve the keeper's
 * player_id once admin has applied the match.
 *
 * @property int $id
 * @property int $match_report_id
 * @property int $sequence
 * @property int $minute
 * @property string $team_side
 * @property int $jersey_number
 * @property string|null $reported_name
 * @property string $outcome
 * @property string|null $opponent_reported_name
 * @property int|null $opponent_jersey_number
 * @property string|null $body_part
 * @property bool $is_penalty
 * @property array<string, mixed>|null $raw_extracted
 */
class MatchGoalkeeperEvent extends Model
{
    public const OUTCOME_CATCH = 'catch';

    public const OUTCOME_PARRY = 'parry';

    public const OUTCOME_CONCEDED = 'conceded';

    public const OUTCOME_OWN_GOAL = 'own_goal';

    public const OUTCOME_OTHER = 'other';

    protected $fillable = [
        'match_report_id', 'sequence', 'minute', 'team_side',
        'jersey_number', 'reported_name',
        'outcome',
        'opponent_reported_name', 'opponent_jersey_number',
        'body_part', 'is_penalty',
        'raw_extracted',
    ];

    protected $casts = [
        'is_penalty' => 'boolean',
        'raw_extracted' => 'array',
    ];

    /** @return BelongsTo<MatchReport, $this> */
    public function matchReport(): BelongsTo
    {
        return $this->belongsTo(MatchReport::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeBahrain(Builder $query): Builder
    {
        return $query->where('team_side', MatchPerformance::TEAM_SIDE_BAHRAIN);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeConceded(Builder $query): Builder
    {
        return $query->where('outcome', self::OUTCOME_CONCEDED);
    }
}
