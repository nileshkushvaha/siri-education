<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Concerns\PreventsHardDeletion;
use Database\Factories\MessagingRestrictionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SRS §17.36 "Restrict messaging" / "Suspend messaging access" — a
 * user-level restriction, never per-conversation. `lifted_at === null`
 * is the single source of truth for "currently active" — written
 * exclusively by MessagingService::applyRestriction()/removeRestriction().
 */
class MessagingRestriction extends Model
{
    /** @use HasFactory<MessagingRestrictionFactory> */
    use HasFactory, HasUuids, PreventsHardDeletion;

    protected $fillable = [
        'user_id',
        'applied_by',
        'reason',
        'applied_at',
        'lifted_at',
        'lifted_by',
        'lifted_reason',
    ];

    protected function casts(): array
    {
        return [
            'applied_at' => 'immutable_datetime',
            'lifted_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function appliedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function liftedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lifted_by');
    }

    public function isActive(): bool
    {
        return $this->lifted_at === null;
    }
}
