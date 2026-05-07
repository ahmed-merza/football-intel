<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\NutritionistAnalysisFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property int $id
 * @property int $player_id
 * @property int|null $generated_by
 * @property string $status
 * @property string|null $model_used
 * @property string|null $error
 * @property string|null $summary_text
 * @property array<string, mixed>|null $payload
 * @property int|null $source_blood_record_id
 * @property int|null $source_inbody_record_id
 * @property CarbonInterface|null $generated_at
 */
class NutritionistAnalysis extends Model implements Auditable
{
    use AuditableTrait;

    /** @use HasFactory<NutritionistAnalysisFactory> */
    use HasFactory;

    use SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $table = 'nutritionist_analyses';

    protected $fillable = [
        'player_id',
        'generated_by',
        'status',
        'model_used',
        'error',
        'summary_text',
        'payload',
        'source_blood_record_id',
        'source_inbody_record_id',
        'generated_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'generated_at' => 'datetime',
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

    /** @return BelongsTo<PlayerRecord, $this> */
    public function bloodRecord(): BelongsTo
    {
        return $this->belongsTo(PlayerRecord::class, 'source_blood_record_id');
    }

    /** @return BelongsTo<PlayerRecord, $this> */
    public function inbodyRecord(): BelongsTo
    {
        return $this->belongsTo(PlayerRecord::class, 'source_inbody_record_id');
    }
}
