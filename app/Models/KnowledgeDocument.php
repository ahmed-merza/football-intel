<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\KnowledgeDocumentFactory;
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
 * @property string $title
 * @property string $source_type
 * @property string $storage_disk
 * @property string $storage_path
 * @property string $language
 * @property array<string, mixed>|null $metadata
 * @property int|null $uploaded_by
 */
class KnowledgeDocument extends Model implements Auditable
{
    use AuditableTrait;

    /** @use HasFactory<KnowledgeDocumentFactory> */
    use HasFactory;

    use SoftDeletes;

    public const SOURCE_NUTRITION_PLAN = 'nutrition_plan';

    public const SOURCE_SUPPLEMENT_GUIDE = 'supplement_guide';

    public const SOURCE_PROTOCOL = 'protocol';

    public const SOURCE_NOTE = 'note';

    protected $fillable = [
        'title',
        'source_type',
        'storage_disk',
        'storage_path',
        'language',
        'metadata',
        'uploaded_by',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    /** @return HasMany<KnowledgeChunk, $this> */
    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class, 'document_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSourceType(Builder $query, string $type): Builder
    {
        return $query->where('source_type', $type);
    }
}
