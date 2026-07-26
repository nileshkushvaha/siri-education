<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A received provider event, already verified/normalized before this
 * row exists (see InstructorPayoutProviderInterface::normalizeEvent()).
 * The ingestion path is internal (reconciliation service / tests) only
 * — no public route writes here. Never hard-deleted; a
 * duplicate event is recorded and linked via duplicate_of_id, not
 * discarded, so the duplicate-detection history stays auditable.
 */
class InstructorPayoutProviderEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'provider',
        'provider_event_id',
        'event_type',
        'provider_payout_id',
        'payload_hash',
        'signature_valid',
        'processing_status',
        'received_at',
        'processed_at',
        'duplicate_of_id',
        'failure_reason',
        'encrypted_payload',
    ];

    protected $hidden = [
        'encrypted_payload',
    ];

    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'encrypted_payload' => 'encrypted:array',
        ];
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }
}
