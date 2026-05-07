<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AlertFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property int $id
 * @property int $player_id
 * @property int|null $record_id
 * @property string $severity
 * @property string $kind
 * @property string $message
 * @property Carbon|null $acknowledged_at
 * @property int|null $acknowledged_by
 */
class Alert extends Model implements Auditable
{
    use AuditableTrait;

    /** @use HasFactory<AlertFactory> */
    use HasFactory;

    use SoftDeletes;

    public const SEVERITY_INFO = 'info';

    public const SEVERITY_WARN = 'warn';

    public const SEVERITY_CRITICAL = 'critical';

    protected $fillable = [
        'player_id',
        'record_id',
        'severity',
        'kind',
        'message',
        'acknowledged_at',
        'acknowledged_by',
    ];

    protected $casts = [
        'acknowledged_at' => 'datetime',
    ];

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** @return BelongsTo<PlayerRecord, $this> */
    public function record(): BelongsTo
    {
        return $this->belongsTo(PlayerRecord::class, 'record_id');
    }

    /** @return BelongsTo<User, $this> */
    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('acknowledged_at');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSeverity(Builder $query, string $severity): Builder
    {
        return $query->where('severity', $severity);
    }
}
