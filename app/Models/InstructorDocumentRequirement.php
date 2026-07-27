<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Concerns\PreventsHardDeletion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Admin-configurable KYC document requirement — replaces
 * the hardcoded InstructorOnboardingService::REQUIRED_DOCUMENT_COLLECTIONS
 * constant. `collection_name` must match a Spatie Media Library
 * collection actually registered on UserProfile (see
 * UserProfile::registerMediaCollections()) — this table only decides
 * which of those collections are currently required/optional/active,
 * it does not itself register or store media.
 *
 * No delete, ever (PreventsHardDeletion, no SoftDeletes) — a historical
 * application may have been evaluated against a requirement row that
 * must remain inspectable. Retiring a requirement is `active = false`,
 * never a row removal.
 */
class InstructorDocumentRequirement extends Model
{
    use HasFactory, LogsActivity, PreventsHardDeletion;

    protected $fillable = [
        'collection_name',
        'label',
        'description',
        'required',
        'accepted_mime_types',
        'max_size_kb',
        'active',
        'sort_order',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'accepted_mime_types' => 'array',
            'max_size_kb' => 'integer',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeRequired($query)
    {
        return $query->where('required', true);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('instructor')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
