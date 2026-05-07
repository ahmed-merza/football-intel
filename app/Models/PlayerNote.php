<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PlayerNoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property int $id
 * @property int $player_id
 * @property int|null $record_id
 * @property string $body
 * @property int $created_by
 */
class PlayerNote extends Model implements Auditable
{
    use AuditableTrait;

    /** @use HasFactory<PlayerNoteFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'player_id',
        'record_id',
        'body',
        'created_by',
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
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
