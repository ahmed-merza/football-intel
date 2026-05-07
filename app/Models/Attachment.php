<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $submission_id
 * @property string $original_filename
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $storage_disk
 * @property string $storage_path
 * @property string $sha256
 * @property int|null $page_count
 * @property bool|null $is_text_pdf
 * @property string|null $extracted_text
 * @property bool $ocr_used
 * @property float|null $ocr_confidence
 * @property string|null $thumbnail_path
 */
class Attachment extends Model
{
    /** @use HasFactory<AttachmentFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'submission_id',
        'original_filename',
        'mime_type',
        'size_bytes',
        'storage_disk',
        'storage_path',
        'sha256',
        'page_count',
        'is_text_pdf',
        'extracted_text',
        'ocr_used',
        'ocr_confidence',
        'thumbnail_path',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'page_count' => 'integer',
        'is_text_pdf' => 'boolean',
        // Medical content — encrypted at rest via the app key. Queries by text
        // are impossible once encrypted; that's intentional for privacy.
        'extracted_text' => 'encrypted',
        'ocr_used' => 'boolean',
        'ocr_confidence' => 'decimal:2',
    ];

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }
}
