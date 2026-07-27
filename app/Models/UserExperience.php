<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmploymentType;
use App\Support\Media\Concerns\HasStandardImageConversions;
use App\Support\Media\StandardImageConversion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class UserExperience extends Model implements HasMedia
{
    use HasFactory, HasStandardImageConversions, InteractsWithMedia, SoftDeletes;

    protected $fillable = [
        'user_id',
        'organization_name',
        'designation',
        'employment_type',
        'industry',
        'location',
        'country_id',
        'state_id',
        'city',
        'description',
        'skills',
        'website',
        'is_current',
        'start_date',
        'end_date',
        'display_order',
        'status',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'employment_type' => EmploymentType::class,
            'skills' => 'array',
            'is_current' => 'boolean',
            'start_date' => 'date',
            'end_date' => 'date',
            'display_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (UserExperience $experience): void {
            $experience->created_by ??= auth()->id();
        });

        static::updating(function (UserExperience $experience): void {
            $experience->updated_by = auth()->id() ?? $experience->updated_by;
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function registerMediaCollections(): void
    {
        // Public — a display logo, evidence-backed by the profile experience timeline.
        $this->addMediaCollection('company_logo')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif']);

        // Private: supporting documents may contain personal/sensitive content.
        $this->addMediaCollection('supporting_documents')
            ->useDisk('local')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);
    }

    /** 150x150 thumbnail for the small company-logo icon in the experience timeline. supporting_documents is left unconverted (no confirmed display consumer). */
    public function registerMediaConversions(?Media $media = null): void
    {
        if ($this->skipStandardImageConversions($media)) {
            return;
        }

        $this->addThumbConversion('company_logo');
    }

    /** Safely falls back to the original until the queued conversion is generated. */
    public function companyLogoThumbUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->urlForStandardConversion('company_logo', StandardImageConversion::Thumb),
        );
    }
}
