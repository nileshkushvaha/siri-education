<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Auth\EmailVerificationOtpService;
use App\Services\PortalResolver;
use App\Support\Media\Concerns\HasStandardImageConversions;
use App\Support\Media\StandardImageConversion;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasMedia, MustVerifyEmail
{
    use HasFactory, HasRoles, HasStandardImageConversions, InteractsWithMedia, LogsActivity, Notifiable;

    // ── Status constants ────────────────────────────────────────────
    public const STATUS_PENDING = 'pending_verification';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_SUSPENDED = 'suspended';

    // Unlock email token TTL — no corresponding settings field; keep as constant
    public const UNLOCK_TOKEN_MINUTES = 60;

    // ────────────────────────────────────────────────────────────────

    protected $fillable = [
        'name',
        'slug',
        'first_name',
        'last_name',
        'email',
        'password',
        'status',
        'email_verified_at',
        'failed_login_count',
        'locked_at',
        'locked_until',
        'lock_reason',
        'unlock_token',
        'unlock_token_expires_at',
        'last_login_at',
        'last_login_ip',
        'last_login_user_agent',
        'password_changed_at',
        'must_change_password',
        'terms_accepted_at',
        'privacy_accepted_at',
        'terms_version',
        'privacy_version',
        'terms_accepted_ip',
        'privacy_accepted_ip',
        'terms_accepted_user_agent',
        'privacy_accepted_user_agent',
        'login_alerts_enabled',
        'new_device_alerts_enabled',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'unlock_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'locked_at' => 'datetime',
            'locked_until' => 'datetime',
            'unlock_token_expires_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'must_change_password' => 'boolean',
            'terms_accepted_at' => 'datetime',
            'privacy_accepted_at' => 'datetime',
            'login_alerts_enabled' => 'boolean',
            'new_device_alerts_enabled' => 'boolean',
            'password' => 'hashed',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    /** Effective-dated compensation agreements (instructors only). */
    public function compensationAgreements(): HasMany
    {
        return $this->hasMany(InstructorCompensationAgreement::class, 'instructor_id');
    }

    public function loginHistories(): HasMany
    {
        return $this->hasMany(LoginHistory::class)->latest('logged_in_at');
    }

    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class);
    }

    public function authoredPosts(): HasMany
    {
        return $this->hasMany(Post::class, 'author_id');
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'causer')->latest();
    }

    public function experiences(): HasMany
    {
        return $this->hasMany(UserExperience::class)->orderBy('display_order');
    }

    public function educations(): HasMany
    {
        return $this->hasMany(UserEducation::class)->orderBy('display_order');
    }

    /** Bookings this user teaches (as host). */
    public function hostedBookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'instructor_id');
    }

    public function teacherSubjects(): HasMany
    {
        return $this->hasMany(TeacherSubject::class, 'teacher_id');
    }

    /** Explicit topic-level coverage — see InstructorSubjectTopic. */
    public function instructorSubjectTopics(): HasMany
    {
        return $this->hasMany(InstructorSubjectTopic::class, 'teacher_id');
    }

    /** Admin-approved Education System/Curriculum eligibility — see InstructorCurriculumEligibility. */
    public function instructorCurriculumEligibilities(): HasMany
    {
        return $this->hasMany(InstructorCurriculumEligibility::class, 'teacher_id');
    }

    public function teacherAvailability(): HasMany
    {
        return $this->hasMany(TeacherAvailability::class, 'teacher_id');
    }

    public function studentLearningGoals(): HasMany
    {
        return $this->hasMany(StudentLearningGoal::class);
    }

    public function studentLearningPlans(): HasMany
    {
        return $this->hasMany(StudentLearningPlan::class, 'student_user_id');
    }

    public function assignedLearningPlans(): HasMany
    {
        return $this->hasMany(StudentLearningPlan::class, 'primary_instructor_user_id');
    }

    public function preferredSubjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'student_preferred_subjects')
            ->withTimestamps();
    }

    public function favoriteInstructors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'student_favorite_instructors', 'student_user_id', 'instructor_user_id')
            ->withTimestamps();
    }

    public function favoriteInstructorRows(): HasMany
    {
        return $this->hasMany(StudentFavoriteInstructor::class, 'student_user_id');
    }

    /** Waitlist entries this user joined as a student (SRS §6.19). */
    public function instructorWaitlistEntries(): HasMany
    {
        return $this->hasMany(InstructorWaitlistEntry::class, 'student_user_id');
    }

    /** Waitlist entries naming this user as the instructor (SRS §10.28/§10.29 demand visibility). */
    public function receivedWaitlistEntries(): HasMany
    {
        return $this->hasMany(InstructorWaitlistEntry::class, 'instructor_user_id');
    }

    public function favoritedByStudentRows(): HasMany
    {
        return $this->hasMany(StudentFavoriteInstructor::class, 'instructor_user_id');
    }

    // ── Accessors ────────────────────────────────────────────────────

    public function getFullNameAttribute(): string
    {
        return trim(($this->first_name ?? '').' '.($this->last_name ?? ''))
            ?: $this->name;
    }

    /**
     * Convenience accessor for Filament/UI code — avatars live on the
     * profile (Spatie Media Library), never on the identity table itself.
     */
    public function avatarUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->profile?->avatarUrl,
        );
    }

    /** 150x150 thumbnail for card/list/dropdown rendering. */
    public function avatarThumbUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->profile?->avatarThumbUrl,
        );
    }

    // ── Authorization helpers ────────────────────────────────────────

    /**
     * Single source of truth for "is this user a super admin". The roles
     * table is the source of truth — every authorization path in the app
     * (Gate::before(), policies, Filament pages/resources, PortalResolver,
     * notification recipients) must call this method instead of checking
     * hasRole('super_admin') or any role ID directly.
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    // ── Role query scopes ────────────────────────────────────────────

    /**
     * Spatie's own `role()` scope pre-resolves the role
     * name via Role::findByName(), which throws RoleDoesNotExist when
     * that row doesn't exist yet (fresh install, partial deployment, a
     * seeder that hasn't run). That's correct for authorization checks
     * (hasRole(), Gate::before(), policies — untouched, unaffected by
     * this scope) but wrong for read-only list/count/report/options
     * queries, where a temporarily-missing role should simply mean "no
     * matching users" — never a 500 on an otherwise-unrelated page.
     *
     * whereHas('roles', ...) by name+guard is the join Spatie's own
     * scope ultimately builds once the role is resolved — this performs
     * the identical query when the role exists, and just matches zero
     * rows (never an exception) when it doesn't. Never calls
     * Role::findByName(), never creates anything — a pure read.
     *
     * Only for read paths. Do not use this to replace hasRole() (already
     * non-throwing — it checks the user's own loaded roles, never
     * resolves the role row) or any authorization/Gate/policy check.
     */
    public function scopeWhereHasRoleNamed(Builder $query, string $name, string $guard = 'web'): Builder
    {
        return $query->whereHas('roles', fn (Builder $q) => $q->where('name', $name)->where('guard_name', $guard));
    }

    // ── Status helpers ───────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isPendingVerification(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isLocked(): bool
    {
        // New-style lock: locked_at is set by AccountProtectionService / LoginSecurityService
        if ($this->locked_at !== null) {
            // locked_until = null means manual-unlock-only (no auto-expiry)
            if ($this->locked_until === null) {
                return true;
            }

            return $this->locked_until->isFuture();
        }

        // Legacy: only locked_until set (pre-migration records, test fixtures, self-unlock flow)
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function isManualLock(): bool
    {
        return $this->locked_at !== null && $this->locked_until === null;
    }

    public function isBlocked(): bool
    {
        return in_array($this->status, [self::STATUS_BLOCKED, self::STATUS_SUSPENDED], true);
    }

    // ── Login tracking ───────────────────────────────────────────────

    public function recordSuccessfulLogin(string $ip, string $userAgent): void
    {
        $this->updateQuietly([
            'failed_login_count' => 0,
            'locked_at' => null,
            'locked_until' => null,
            'lock_reason' => null,
            'unlock_token' => null,
            'unlock_token_expires_at' => null,
            'last_login_at' => now(),
            'last_login_ip' => $ip,
            'last_login_user_agent' => $userAgent,
        ]);
    }

    // ── Account unlock (self-service) ────────────────────────────────

    public function generateUnlockToken(): string
    {
        $plain = Str::random(64);
        $this->updateQuietly([
            'unlock_token' => hash('sha256', $plain),
            'unlock_token_expires_at' => now()->addMinutes(self::UNLOCK_TOKEN_MINUTES),
        ]);

        return $plain;
    }

    public function unlock(): void
    {
        $this->updateQuietly([
            'failed_login_count' => 0,
            'locked_at' => null,
            'locked_until' => null,
            'lock_reason' => null,
            'unlock_token' => null,
            'unlock_token_expires_at' => null,
        ]);
    }

    // ── Email Verification notification override ─────────────────────

    /**
     * Verification is code-based, not link-based: every caller of the
     * framework's contract method (login resend, verification screen,
     * admin approval) issues a fresh one-time code instead of a signed
     * URL. EmailVerificationOtpService owns the challenge lifecycle.
     */
    public function sendEmailVerificationNotification(): void
    {
        app(EmailVerificationOtpService::class)->issue($this);
    }

    // ── Filament ─────────────────────────────────────────────────────

    /**
     * Admin Portal eligibility (super_admin, manager) is decided by
     * PortalResolver — the single source of truth for portal membership.
     * This method only adds the account-state checks already required for
     * any panel session (active, and verified unless super_admin).
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if (! app(PortalResolver::class)->usesAdminPortal($this)) {
            return false;
        }

        if ($this->isSuperAdmin()) {
            return $this->isActive();
        }

        return $this->isActive() && $this->hasVerifiedEmail();
    }

    // ── Route key ───────────────────────────────────────────────────

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    // ── Media ───────────────────────────────────────────────────────

    public function registerMediaCollections(): void
    {
        // Explicit public disk — see UserProfile::registerMediaCollections().
        $this->addMediaCollection('instructor_cover')
            ->useDisk('public')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
    }

    /** 800px display conversion for the public instructor cover banner. */
    public function registerMediaConversions(?Media $media = null): void
    {
        if ($this->skipStandardImageConversions($media)) {
            return;
        }

        $this->addDisplayConversion('instructor_cover');
    }

    /** Safely falls back to the original until the queued conversion is generated. */
    public function coverDisplayUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->urlForStandardConversion('instructor_cover', StandardImageConversion::Display),
        );
    }

    // ── Activity Log ─────────────────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'first_name', 'last_name', 'email', 'status', 'email_verified_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('user');
    }
}
