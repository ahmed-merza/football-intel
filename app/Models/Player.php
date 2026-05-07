<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PlayerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property int $id
 * @property string $full_name
 * @property string|null $name_ar
 * @property string|null $club
 * @property string|null $position
 * @property Carbon|null $date_of_birth
 * @property string|null $nationality
 * @property int|null $height_cm
 * @property float|null $weight_kg
 * @property string|null $preferred_foot
 * @property string|null $player_code
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $photo_path
 * @property string $status
 * @property-read int|null $age
 * @property-read float|null $bmi
 */
class Player extends Model implements Auditable
{
    use AuditableTrait;

    /** @use HasFactory<PlayerFactory> */
    use HasFactory;

    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'full_name',
        'name_ar',
        'club',
        'position',
        'date_of_birth',
        'nationality',
        'height_cm',
        'weight_kg',
        'preferred_foot',
        'player_code',
        'phone',
        'email',
        'photo_path',
        'status',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'height_cm' => 'integer',
        'weight_kg' => 'decimal:2',
    ];

    /** @return HasMany<Submission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    /** @return HasMany<PlayerRecord, $this> */
    public function records(): HasMany
    {
        return $this->hasMany(PlayerRecord::class);
    }

    /** @return HasMany<RecordMetric, $this> */
    public function metrics(): HasMany
    {
        return $this->hasMany(RecordMetric::class);
    }

    /** @return HasMany<Alert, $this> */
    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    /** @return HasMany<PlayerNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(PlayerNote::class);
    }

    /** @return HasMany<NutritionistAnalysis, $this> */
    public function nutritionistAnalyses(): HasMany
    {
        return $this->hasMany(NutritionistAnalysis::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    // Accessors use Attribute's invariant TGet template, which fights Larastan's
    // exact-type check. Suppressing the generics warning locally keeps the rest
    // of the codebase holding the line on type coverage.
    /** @phpstan-ignore missingType.generics */
    protected function age(): Attribute
    {
        return Attribute::get(
            fn (): ?int => $this->date_of_birth?->age,
        );
    }

    /** @phpstan-ignore missingType.generics */
    protected function bmi(): Attribute
    {
        return Attribute::get(function (): ?float {
            if ($this->height_cm === null || $this->weight_kg === null) {
                return null;
            }
            $heightM = $this->height_cm / 100;

            return round(((float) $this->weight_kg) / ($heightM * $heightM), 1);
        });
    }
}
