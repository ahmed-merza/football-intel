<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Durable correlation row between a single outbound n8n call and its
 * eventual result — whether that result lands via the sync HTTP
 * response or the asynchronous callback POST that arrives later.
 *
 * Lifecycle:
 *   pending   — request sent, no result yet
 *   completed — result applied (sync or callback)
 *   failed    — non-recoverable error returned by n8n / parser
 *   expired   — reaper passed it; no callback ever arrived
 *
 * The webhook handler dedupes by correlation_id + status: a callback
 * arriving for an already-completed row is a no-op (200 OK), which
 * lets us hit n8n with sync + callback every time without worrying
 * about double-application.
 *
 * @property int $id
 * @property string $correlation_id
 * @property int|null $record_id
 * @property int|null $analysis_id
 * @property int|null $match_report_id
 * @property string $kind
 * @property string $status
 * @property array<string, mixed>|null $request
 * @property array<string, mixed>|null $result
 * @property string|null $error
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $processed_at
 * @property Carbon $expires_at
 * @property-read PlayerRecord|null $record
 * @property-read NutritionistAnalysis|null $analysis
 * @property-read MatchReport|null $matchReport
 */
class PendingExtraction extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const KIND_BLOOD_TEST = 'blood_test';

    public const KIND_INBODY = 'inbody';

    public const KIND_NUTRITION_PLAN = 'nutrition_plan';

    public const KIND_CLASSIFIER = 'classifier';

    public const KIND_NUTRITIONIST_ANALYSIS = 'nutritionist_analysis';

    public const KIND_MATCH_REPORT = 'match_report';

    protected $fillable = [
        'correlation_id',
        'record_id',
        'analysis_id',
        'match_report_id',
        'kind',
        'status',
        'request',
        'result',
        'error',
        'processed_at',
        'expires_at',
    ];

    protected $casts = [
        'request' => AsArrayObject::class,
        'result' => AsArrayObject::class,
        'processed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /** @return BelongsTo<PlayerRecord, $this> */
    public function record(): BelongsTo
    {
        return $this->belongsTo(PlayerRecord::class, 'record_id');
    }

    /** @return BelongsTo<NutritionistAnalysis, $this> */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(NutritionistAnalysis::class, 'analysis_id');
    }

    /** @return BelongsTo<MatchReport, $this> */
    public function matchReport(): BelongsTo
    {
        return $this->belongsTo(MatchReport::class, 'match_report_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_EXPIRED,
        ], true);
    }
}
