<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InstructorStatus;
use App\Enums\StudentStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class UserProfile extends Model implements HasMedia
{
    use InteractsWithMedia, LogsActivity, SoftDeletes;

    protected $fillable = [
        'user_id',
        'headline',
        'designation',
        'short_bio',
        'bio',
        'phone',
        'gender',
        'date_of_birth',
        'address',
        'city',
        'country_id',
        'state_id',
        'postal_code',
        'timezone',
        'language',
        'website',
        'facebook',
        'twitter',
        'linkedin',
        'github',
        'instagram',
        'youtube',
        'profile_visibility',
        'show_email',
        'show_phone',
        'show_social_links',
        'notification_preferences',
        'is_featured',
        'featured_order',
        'is_instructor_verified',
        'instructor_status',
        'student_status',
        'assignment_priority',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'notification_preferences' => 'array',
            'show_email' => 'boolean',
            'show_phone' => 'boolean',
            'show_social_links' => 'boolean',
            'profile_completion' => 'integer',
            'is_featured' => 'boolean',
            'is_instructor_verified' => 'boolean',
            'instructor_status' => InstructorStatus::class,
            'student_status' => StudentStatus::class,
            'assignment_priority' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (UserProfile $profile): void {
            $profile->created_by ??= auth()->id();
        });

        static::updating(function (UserProfile $profile): void {
            $profile->updated_by = auth()->id() ?? $profile->updated_by;
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

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('avatar')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif']);

        $this->addMediaCollection('cover')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
    }

    public function avatarUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->getFirstMediaUrl('avatar') ?: null,
        );
    }

    public function coverUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->getFirstMediaUrl('cover') ?: null,
        );
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('profile')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
