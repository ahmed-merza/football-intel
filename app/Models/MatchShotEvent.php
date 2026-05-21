<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Single shot event extracted from the Shot Details section of an
 * AGCFF/Wyscout match-report PDF. Phase-2 of the match-report pipeline.
 *
 * Sibling of {@see MatchPerformance} — not a child of it. Both belong
 * directly to the {@see MatchReport}. To resolve a shot to a player
 * (i.e. find the Bahrain admin's player_id once they've gone through
 * the resolution UI on the parent match), join via match_performances
 * on (match_report_id, team_side, jersey_number).
 *
 * No softDeletes — derived data; re-extract wipes + reinserts.
 *
 * @property int $id
 * @property int $match_report_id
 * @property int $sequence
 * @property int $minute
 * @property int|null $seconds
 * @property string $team_side
 * @property int $jersey_number
 * @property string|null $reported_name
 * @property string|null $body_part
 * @property string $outcome
 * @property bool $is_penalty
 * @property bool $is_own_goal
 * @property array<int, array<string, mixed>>|null $buildup_chain
 * @property array<string, mixed>|null $raw_extracted
 */
class MatchShotEvent extends Model
{
    public const OUTCOME_GOAL = 'goal';

    public const OUTCOME_ON_TARGET = 'on_target';

    public const OUTCOME_BLOCKED = 'blocked';

    public const OUTCOME_MISSED = 'missed';

    public const OUTCOME_OTHER = 'other';

    protected $fillable = [
        'match_report_id', 'sequence', 'minute', 'seconds', 'team_side',
        'jersey_number', 'reported_name', 'body_part', 'outcome',
        'is_penalty', 'is_own_goal', 'buildup_chain', 'raw_extracted',
    ];

    protected $casts = [
        'is_penalty' => 'boolean',
        'is_own_goal' => 'boolean',
        'buildup_chain' => 'array',
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
    public function scopeGoals(Builder $query): Builder
    {
        return $query->where('outcome', self::OUTCOME_GOAL);
    }
}
