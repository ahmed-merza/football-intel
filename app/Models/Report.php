<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ReportFactory;
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
 * @property int|null $player_id
 * @property string $type
 * @property string $title
 * @property Carbon|null $period_from
 * @property Carbon|null $period_to
 * @property string $storage_disk
 * @property string $storage_path
 * @property int|null $generated_by
 * @property array<string, mixed>|null $meta
 */
class Report extends Model implements Auditable
{
    use AuditableTrait;

    /** @use HasFactory<ReportFactory> */
    use HasFactory;

    use SoftDeletes;

    public const TYPE_PLAYER = 'player';

    public const TYPE_TEAM = 'team';

    protected $fillable = [
        'player_id',
        'type',
        'title',
        'period_from',
        'period_to',
        'storage_disk',
        'storage_path',
        'generated_by',
        'meta',
    ];

    protected $casts = [
        'period_from' => 'date',
        'period_to' => 'date',
        'meta' => 'array',
    ];

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** @return BelongsTo<User, $this> */
    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }
}
