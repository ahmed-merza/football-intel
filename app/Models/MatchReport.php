<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\MatchReportFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Top-level match-report row — one per uploaded AGCFF/Wyscout-style PDF.
 * Owns many {@see MatchPerformance} rows (both Bahrain and opposition);
 * resolved Bahrain performances also produce a {@see PlayerRecord} with
 * category `match_performance` so the existing timeline + metric fan-out
 * + AI agent context paths pick the data up without new wiring.
 *
 * Status lifecycle:
 *   pending           — created, extraction not yet dispatched
 *   extracting        — sync n8n call in flight
 *   awaiting_callback — sync timed out; n8n still working, will POST result later
 *   extracted         — payload landed; awaiting admin player-resolution step
 *   applied           — admin resolved + per-player rows committed (happy terminal)
 *   failed            — non-timeout error from the extractor (retry button shows)
 *
 * @property int $id
 * @property string $source
 * @property string|null $competition
 * @property string|null $stage
 * @property CarbonInterface|null $match_date
 * @property string|null $kickoff_time
 * @property string|null $venue
 * @property string|null $home_team_name
 * @property string|null $away_team_name
 * @property int|null $home_score
 * @property int|null $away_score
 * @property string|null $bahrain_side
 * @property string|null $opponent_name
 * @property int|null $submission_id
 * @property int|null $attachment_id
 * @property int|null $uploaded_by
 * @property string $status
 * @property string|null $extraction_error
 * @property array<string, mixed>|null $raw_extracted
 * @property CarbonInterface|null $extracted_at
 * @property CarbonInterface|null $applied_at
 */
class MatchReport extends Model implements Auditable
{
    use AuditableTrait;

    /** @use HasFactory<MatchReportFactory> */
    use HasFactory;

    use SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_EXTRACTING = 'extracting';

    public const STATUS_AWAITING_CALLBACK = 'awaiting_callback';

    public const STATUS_EXTRACTED = 'extracted';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_FAILED = 'failed';

    public const SOURCE_AGCFF = 'agcff_match_report';

    public const BAHRAIN_SIDE_HOME = 'home';

    public const BAHRAIN_SIDE_AWAY = 'away';

    protected $fillable = [
        'source',
        'competition',
        'stage',
        'match_date',
        'kickoff_time',
        'venue',
        'home_team_name',
        'away_team_name',
        'home_score',
        'away_score',
        'bahrain_side',
        'opponent_name',
        'submission_id',
        'attachment_id',
        'uploaded_by',
        'status',
        'extraction_error',
        'raw_extracted',
        'extracted_at',
        'applied_at',
    ];

    protected $casts = [
        'match_date' => 'date',
        'home_score' => 'integer',
        'away_score' => 'integer',
        // jsonb on pgsql, json on mysql — same rationale as PlayerRecord.extracted:
        // need to read individual fields for downstream applies + UI; protection
        // at rest is delegated to disk/backup-level encryption.
        'raw_extracted' => 'array',
        'extracted_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    /** @return HasMany<MatchPerformance, $this> */
    public function performances(): HasMany
    {
        return $this->hasMany(MatchPerformance::class);
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /** @return BelongsTo<Attachment, $this> */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Convenience: opposition team name, derived from bahrain_side when both
     * sides are populated. Falls back to the stored opponent_name (which the
     * applier sets at extraction time) if for some reason bahrain_side isn't
     * resolved (e.g. neither team is Bahrain — shouldn't happen in V1).
     */
    public function opponent(): ?string
    {
        return match ($this->bahrain_side) {
            self::BAHRAIN_SIDE_HOME => $this->away_team_name,
            self::BAHRAIN_SIDE_AWAY => $this->home_team_name,
            default => $this->opponent_name,
        };
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeNeedingResolution(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_EXTRACTED);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeApplied(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPLIED);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_APPLIED, self::STATUS_FAILED], true);
    }
}
