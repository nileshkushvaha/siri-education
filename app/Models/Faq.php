<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FaqAudience;
use App\Enums\FaqStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Faq extends Model
{
    use HasUuids, LogsActivity, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'faq_category_id',
        'question',
        'answer',
        'audience',
        'display_order',
        'featured',
        'status',
        'published_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'audience' => 'array',
        'featured' => 'boolean',
        'status' => FaqStatus::class,
        'published_at' => 'datetime',
        'display_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (Faq $faq): void {
            $faq->created_by = auth()->id() ?? null;
            $faq->updated_by = auth()->id() ?? null;
        });

        static::updating(function (Faq $faq): void {
            $faq->updated_by = auth()->id() ?? null;

            if ($faq->isDirty('status') && $faq->status === FaqStatus::Published && $faq->published_at === null) {
                $faq->published_at = now();
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FaqCategory::class, 'faq_category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', FaqStatus::Published)
            ->where(function (Builder $q): void {
                $q->whereNull('published_at')->orWhere('published_at', '<=', now());
            });
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('featured', true);
    }

    public function scopeForAudience(Builder $query, array $audiences): Builder
    {
        return $query->where(function (Builder $q) use ($audiences): void {
            foreach ($audiences as $audience) {
                $q->orWhereJsonContains('audience', $audience);
            }
        });
    }

    public function scopeSearchTerm(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $q) use ($term): void {
            $q->where('question', 'like', "%{$term}%")
                ->orWhere('answer', 'like', "%{$term}%");
        });
    }

    public function hasAudience(FaqAudience|string $audience): bool
    {
        $value = $audience instanceof FaqAudience ? $audience->value : $audience;

        return in_array($value, $this->audience ?? [], true);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['faq_category_id', 'question', 'answer', 'audience', 'display_order', 'featured', 'status'])
            ->useLogName('faqs')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
