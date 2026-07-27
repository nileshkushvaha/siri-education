<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EducationLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class UserEducation extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, SoftDeletes;

    // "education" is an uncountable noun to Doctrine's inflector, so
    // Eloquent's default pluralization would resolve to "user_education".
    protected $table = 'user_educations';

    protected $fillable = [
        'user_id',
        'institution_name',
        'degree',
        'field_of_study',
        'education_level',
        'country_id',
        'state_id',
        'city',
        'grade',
        'percentage',
        'cgpa',
        'description',
        'start_date',
        'end_date',
        'is_current',
        'certificate_number',
        'display_order',
        'status',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'education_level' => EducationLevel::class,
            'percentage' => 'decimal:2',
            'cgpa' => 'decimal:2',
            'is_current' => 'boolean',
            'start_date' => 'date',
            'end_date' => 'date',
            'display_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (UserEducation $education): void {
            $education->created_by ??= auth()->id();
        });

        static::updating(function (UserEducation $education): void {
            $education->updated_by = auth()->id() ?? $education->updated_by;
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

    /** Private: education/KYC-adjacent documents must never sit on the public disk. */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('certificate')
            ->useDisk('local')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);

        $this->addMediaCollection('transcript')
            ->useDisk('local')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);

        $this->addMediaCollection('degree_document')
            ->useDisk('local')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);
    }
}
