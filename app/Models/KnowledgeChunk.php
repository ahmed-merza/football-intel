<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\KnowledgeChunkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Chunk + embedding. Embeddings are written via raw insertions / the embedding
 * service — NOT through mass assignment. Querying uses the native
 * `nearestNeighbors()` relation on Laravel 13.
 *
 * Driver requirement: similarity / nearest-neighbour queries require Postgres
 * + pgvector. The migration falls back to `longText` on MySQL/MariaDB so the
 * schema can be created in dev, but any vector SQL will fail there. Call
 * `KnowledgeChunk::assertVectorCapable()` before running RAG retrieval to
 * surface a clear error instead of a cryptic SQL one.
 *
 * @property int $id
 * @property int $document_id
 * @property int $chunk_index
 * @property string $content
 * @property array<string, mixed>|null $metadata
 */
class KnowledgeChunk extends Model
{
    /** @use HasFactory<KnowledgeChunkFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'document_id',
        'chunk_index',
        'content',
        'embedding',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'chunk_index' => 'integer',
    ];

    // The embedding column is a pgvector — cast + read/write happens via
    // Laravel 13's AI SDK helpers and the `nearestNeighbors()` query method.
    // We don't mass-cast it on the model to avoid accidental PHP-land
    // manipulation of a 1024-dimension float array.

    /** @return BelongsTo<KnowledgeDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'document_id');
    }

    /**
     * Guard for RAG retrieval code: throws on non-pgsql so vector SQL doesn't
     * fail with a cryptic driver error. Call this at the entry of any service
     * that issues `<->` / `<=>` / `nearestNeighbors()` against this table.
     */
    public static function assertVectorCapable(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'pgsql') {
            throw new RuntimeException(
                "Vector / nearest-neighbour queries on knowledge_chunks require Postgres + pgvector. Active driver: {$driver}. Switch DB_CONNECTION to pgsql, or skip the RAG-retrieval code path on this driver."
            );
        }
    }
}
