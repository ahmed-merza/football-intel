<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SubmissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $channel
 * @property int|null $player_id
 * @property int|null $uploaded_by
 * @property Carbon $received_at
 * @property string $status
 * @property Carbon|null $processed_at
 * @property string|null $notes
 */
class Submission extends Model
{
    /** @use HasFactory<SubmissionFactory> */
    use HasFactory;

    use SoftDeletes;

    public const CHANNEL_MANUAL_UPLOAD = 'manual_upload';

    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const CHANNEL_EMAIL = 'email';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_CLASSIFIED = 'classified';

    public const STATUS_FAILED = 'failed';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    protected $fillable = [
        'channel',
        'player_id',
        'uploaded_by',
        'received_at',
        'status',
        'processed_at',
        'notes',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /** @return HasMany<PlayerRecord, $this> */
    public function records(): HasMany
    {
        return $this->hasMany(PlayerRecord::class);
    }
}
