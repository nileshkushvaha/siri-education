<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Concerns\PreventsHardDeletion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Admin-managed, reusable package quantity rule (e.g. "14 paid
 * lessons + 1 bonus lesson") — see docs/architecture/domain-registry.md
 * "Personalized Packages". Deliberately carries no price of any kind:
 * price is always resolved per InstructorPackageProposal from the
 * existing StudentLessonPrice matrix (App\Booking\Services\
 * StudentLessonPriceResolver) — never configured or stored here. An
 * instructor picks a rule; only paid_quantity/bonus_quantity/
 * total_quantity travel from it into the proposal's own snapshot.
 *
 * Historical safety: a rule's id/quantities get snapshotted onto
 * InstructorPackageProposal rows, the same class of "id must remain
 * stable, and a later edit must never rewrite history" concern as
 * EducationSystem/EducationSystemLevel. PreventsHardDeletion blocks
 * forceDelete(); normal delete()/restore() (deactivating a rule)
 * remains unaffected.
 */
class PackageBenefitRule extends Model
{
    use HasUuids, LogsActivity, PreventsHardDeletion, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $attributes = [
        'bonus_quantity' => 0,
        'is_active' => true,
    ];

    protected $fillable = [
        'name',
        'paid_quantity',
        'bonus_quantity',
        'total_quantity',
        'validity_days',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'paid_quantity' => 'integer',
            'bonus_quantity' => 'integer',
            'total_quantity' => 'integer',
            'validity_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PackageBenefitRule $rule): void {
            $rule->created_by ??= auth()->id();
            $rule->updated_by ??= auth()->id();
        });

        static::updating(function (PackageBenefitRule $rule): void {
            $rule->updated_by = auth()->id();
        });
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(InstructorPackageProposal::class, 'package_benefit_rule_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Active rules only — the pool selectable by an instructor. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'paid_quantity', 'bonus_quantity', 'total_quantity', 'validity_days', 'is_active'])
            ->useLogName('package_benefit_rules')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
