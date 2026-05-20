<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $slug
 * @property string $label_en
 * @property string $label_ar
 * @property string|null $description
 * @property int $sort_order
 */
class RecordCategory extends Model
{
    protected $fillable = ['slug', 'label_en', 'label_ar', 'description', 'sort_order'];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    /**
     * Canonical slugs — matches the seed in the migration. Use these constants
     * whenever you need to reference a category programmatically instead of
     * hard-coding strings.
     */
    public const BLOOD_TEST = 'blood_test';

    public const INBODY = 'inbody';

    public const GPS_WEARABLE = 'gps_wearable';

    public const NUTRITION_PLAN = 'nutrition_plan';

    public const HYDRATION_SUPPLEMENT_PLAN = 'hydration_supplement_plan';

    public const COACH_FEEDBACK = 'coach_feedback';

    public const MATCH_ACTIVITY = 'match_activity';

    public const MATCH_PERFORMANCE = 'match_performance';

    public const OTHER = 'other';

    /** @return HasMany<PlayerRecord, $this> */
    public function records(): HasMany
    {
        return $this->hasMany(PlayerRecord::class, 'category_id');
    }

    // See Player.php for why the generics warning is suppressed on Attribute accessors.
    /** @phpstan-ignore missingType.generics */
    protected function label(): Attribute
    {
        // App locale-aware label — Arabic when app()->getLocale() === 'ar', else English.
        return Attribute::get(
            fn (): string => app()->getLocale() === 'ar' ? $this->label_ar : $this->label_en,
        );
    }
}
