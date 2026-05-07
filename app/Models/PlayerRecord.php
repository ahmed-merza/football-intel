<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\PlayerRecordFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property int $id
 * @property int $player_id
 * @property int $category_id
 * @property CarbonInterface $record_date
 * @property int|null $submission_id
 * @property int|null $primary_attachment_id
 * @property array<string, mixed> $extracted
 * @property array<string, mixed>|null $analysis
 * @property CarbonInterface|null $analysis_generated_at
 * @property string|null $summary_text
 * @property string|null $source_lab
 * @property bool $reviewed
 * @property CarbonInterface|null $reviewed_at
 * @property int|null $reviewed_by
 * @property string|null $admin_notes
 */
class PlayerRecord extends Model implements Auditable
{
    use AuditableTrait;

    /** @use HasFactory<PlayerRecordFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'player_records';

    protected $fillable = [
        'player_id',
        'category_id',
        'record_date',
        'submission_id',
        'primary_attachment_id',
        'extracted',
        'analysis',
        'analysis_generated_at',
        'summary_text',
        'source_lab',
        'reviewed',
        'reviewed_at',
        'reviewed_by',
        'admin_notes',
    ];

    protected $casts = [
        'record_date' => 'date',
        // jsonb, NOT encrypted at the model layer — the fan-out to `record_metrics`
        // and dashboard queries need to read individual fields. Protection for
        // medical data at rest is handled by disk/backup-level encryption
        // (Postgres TDE / encrypted-disk / KMS on backups) instead.
        'extracted' => 'array',
        'analysis' => 'array',
        'analysis_generated_at' => 'datetime',
        'reviewed' => 'boolean',
        'reviewed_at' => 'datetime',
    ];

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** @return BelongsTo<RecordCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(RecordCategory::class, 'category_id');
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /** @return BelongsTo<Attachment, $this> */
    public function primaryAttachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'primary_attachment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return HasMany<RecordMetric, $this> */
    public function metrics(): HasMany
    {
        return $this->hasMany(RecordMetric::class, 'record_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOfCategory(Builder $query, string $slug): Builder
    {
        return $query->whereHas(
            'category',
            fn (Builder $q) => $q->where('slug', $slug),
        );
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForPlayer(Builder $query, int $playerId): Builder
    {
        return $query->where('player_id', $playerId);
    }
}
