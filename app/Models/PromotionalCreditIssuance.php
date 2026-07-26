<?php

declare(strict_types=1);

namespace App\Models;

use App\PromotionalCredits\Enums\PromotionalCreditIssuanceType;
use App\Support\Concerns\PreventsHardDeletion;
use App\Support\Concerns\PreventsUpdates;
use Database\Factories\PromotionalCreditIssuanceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable promotional-credit issuance record (SRS
 * §16.17-§16.19) — written exclusively by
 * PromotionalCreditService::issueCampaignCredit()/issueManualCredit().
 * Never updated, never deleted.
 */
class PromotionalCreditIssuance extends Model
{
    /** @use HasFactory<PromotionalCreditIssuanceFactory> */
    use HasFactory, HasUuids, PreventsHardDeletion, PreventsUpdates;

    protected $fillable = [
        'campaign_id',
        'student_id',
        'wallet_ledger_entry_id',
        'amount_minor',
        'currency_code',
        'issuance_type',
        'issued_by',
        'reason',
        'idempotency_key',
        'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'issuance_type' => PromotionalCreditIssuanceType::class,
            'issued_at' => 'immutable_datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(PromotionalCreditCampaign::class, 'campaign_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function walletLedgerEntry(): BelongsTo
    {
        return $this->belongsTo(WalletLedgerEntry::class);
    }

    public function issuedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
