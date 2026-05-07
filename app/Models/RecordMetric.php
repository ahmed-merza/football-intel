<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RecordMetricFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $record_id
 * @property int $player_id
 * @property int $category_id
 * @property Carbon $record_date
 * @property string $metric_key
 * @property float $metric_value
 * @property string|null $unit
 * @property float|null $ref_low
 * @property float|null $ref_high
 * @property string|null $flag
 */
class RecordMetric extends Model
{
    /** @use HasFactory<RecordMetricFactory> */
    use HasFactory;

    use SoftDeletes;

    public const FLAG_LOW = 'low';

    public const FLAG_NORMAL = 'normal';

    public const FLAG_HIGH = 'high';

    public const FLAG_CRITICAL = 'critical';

    protected $fillable = [
        'record_id',
        'player_id',
        'category_id',
        'record_date',
        'metric_key',
        'metric_value',
        'unit',
        'ref_low',
        'ref_high',
        'flag',
    ];

    protected $casts = [
        'record_date' => 'date',
        'metric_value' => 'decimal:4',
        'ref_low' => 'decimal:4',
        'ref_high' => 'decimal:4',
    ];

    /** @return BelongsTo<PlayerRecord, $this> */
    public function record(): BelongsTo
    {
        return $this->belongsTo(PlayerRecord::class, 'record_id');
    }

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForPlayer(Builder $query, int $playerId): Builder
    {
        return $query->where('player_id', $playerId);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeKey(Builder $query, string $metricKey): Builder
    {
        return $query->where('metric_key', $metricKey);
    }
}
