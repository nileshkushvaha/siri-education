<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InstructorResponseTime;
use App\Enums\InstructorStatus;
use App\Enums\StudentStatus;
use App\Enums\ThemePreference;
use App\Support\Media\Concerns\HasStandardImageConversions;
use App\Support\Media\StandardImageConversion;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class UserProfile extends Model implements HasMedia
{
    use HasStandardImageConversions, InteractsWithMedia, LogsActivity, SoftDeletes;

    protected $fillable = [
        'user_id',
        'headline',
        'short_bio',
        'bio',
        'instructor_teaching_experience_summary',
        'instructor_teaching_philosophy',
        'phone',
        'phone_country_iso2',
        'phone_dial_code',
        'phone_national_number',
        'phone_e164',
        'phone_verified_at',
        'phone_verification_status',
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
        'consents_to_recording',
        'notification_preferences',
        'theme_preference',
        'is_featured',
        'featured_order',
        'is_instructor_verified',
        'response_time_minutes',
        'offers_demo',
        'instructor_status',
        'instructor_academic_level_ids',
        'instructor_teaching_language_ids',
        'instructor_application_started_at',
        'instructor_application_submitted_at',
        'instructor_reviewed_at',
        'instructor_reviewed_by',
        'instructor_review_reason',
        'instructor_documents_requested_reason',
        'student_status',
        'student_status_changed_at',
        'student_status_changed_by',
        'student_status_reason',
        'student_academic_level_id',
        'student_preferred_language_id',
        'assignment_priority',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'phone_verified_at' => 'datetime',
            'notification_preferences' => 'array',
            'theme_preference' => ThemePreference::class,
            'show_email' => 'boolean',
            'show_phone' => 'boolean',
            'show_social_links' => 'boolean',
            'consents_to_recording' => 'boolean',
            'profile_completion' => 'integer',
            'is_featured' => 'boolean',
            'is_instructor_verified' => 'boolean',
            'response_time_minutes' => InstructorResponseTime::class,
            'offers_demo' => 'boolean',
            'instructor_status' => InstructorStatus::class,
            'instructor_academic_level_ids' => 'array',
            'instructor_teaching_language_ids' => 'array',
            'instructor_application_started_at' => 'datetime',
            'instructor_application_submitted_at' => 'datetime',
            'instructor_reviewed_at' => 'datetime',
            'student_status' => StudentStatus::class,
            'student_status_changed_at' => 'datetime',
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

    public function studentAcademicLevel(): BelongsTo
    {
        return $this->belongsTo(AcademicLevel::class, 'student_academic_level_id');
    }

    public function studentPreferredLanguage(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'student_preferred_language_id');
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
        // Explicit public disk — never dependent on the package's
        // disk_name default staying 'public' once KYC hardening
        // tightens it elsewhere. See UserProfile/User docblocks.
        $this->addMediaCollection('avatar')
            ->useDisk('public')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif']);

        $this->addMediaCollection('cover')
            ->useDisk('public')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif']);

        foreach (['government_id', 'address_proof', 'education_certificate', 'teaching_certificate', 'resume'] as $collection) {
            $this->addMediaCollection($collection)
                ->useDisk('local')
                ->singleFile()
                ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);
        }

        $this->addMediaCollection('introduction_video')
            ->useDisk('local')
            ->singleFile()
            ->acceptsMimeTypes(['video/mp4', 'video/webm', 'video/quicktime']);
    }

    /**
     * Avatar gets a fixed 150x150 thumbnail (card/list/dropdown
     * use); cover gets an 800px display conversion (profile banner use).
     * Both KYC document collections and introduction_video are
     * deliberately left unconverted.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        if ($this->skipStandardImageConversions($media)) {
            return;
        }

        $this->addThumbConversion('avatar');
        $this->addDisplayConversion('cover');
    }

    public function avatarUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->getFirstMediaUrl('avatar') ?: null,
        );
    }

    /** 150x150 thumbnail for card/list/dropdown rendering, safely falling back to the original until the queued conversion is generated. */
    public function avatarThumbUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->urlForStandardConversion('avatar', StandardImageConversion::Thumb),
        );
    }

    public function coverUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->getFirstMediaUrl('cover') ?: null,
        );
    }

    /** 800px display conversion for the public/account profile banner, safely falling back to the original until generated. */
    public function coverDisplayUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->urlForStandardConversion('cover', StandardImageConversion::Display),
        );
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('profile')
            ->logFillable()
            ->logExcept(['phone', 'phone_dial_code', 'phone_national_number', 'phone_e164'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
