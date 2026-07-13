<?php

declare(strict_types=1);

namespace App\Models;

use App\Reviews\Enums\LessonReviewEligibilityMode;
use Database\Factories\ReviewTagFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A configured, admin-curated review tag. Students choose from these —
 * never free text — so a submission's `tags` snapshot always resolves
 * to a known, safe label. Deliberately separate from the CMS `Tag`
 * model (Posts), which is an unrelated content-tagging system.
 */
class ReviewTag extends Model
{
    /** @use HasFactory<ReviewTagFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'key',
        'label',
        'applicable_modes',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'applicable_modes' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeApplicableTo(Builder $query, LessonReviewEligibilityMode $mode): Builder
    {
        return $query->whereJsonContains('applicable_modes', $mode->value);
    }

    /** @return array{key: string, label: string} */
    public function snapshot(): array
    {
        return ['key' => $this->key, 'label' => $this->label];
    }
}
