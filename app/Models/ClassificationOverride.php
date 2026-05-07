<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Immutable audit row — no soft delete, no updated_at, no factory. Rows are
 * written by the inline-edit flow in the review UI (AI extraction corrections)
 * via a dedicated service, never mass-assigned from a controller.
 *
 * @property int $id
 * @property int $submission_id
 * @property int|null $attachment_id
 * @property string $field
 * @property string|null $old_value
 * @property string|null $new_value
 * @property int $corrected_by
 * @property Carbon $corrected_at
 * @property Carbon $created_at
 */
class ClassificationOverride extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'submission_id',
        'attachment_id',
        'field',
        'old_value',
        'new_value',
        'corrected_by',
        'corrected_at',
    ];

    protected $casts = [
        'corrected_at' => 'datetime',
        'created_at' => 'datetime',
    ];

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
    public function corrector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }
}
