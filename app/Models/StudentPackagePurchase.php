<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\ImmutableRecordCannotBeUpdatedException;
use App\Package\Enums\PackagePurchaseStatus;
use App\Payments\Contracts\Payable;
use App\Support\Concerns\PreventsHardDeletion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Phase 4B.2 — what the student actually bought, created the moment an
 * approved proposal is accepted.
 *
 * This is the first real App\Payments\Contracts\Payable: it registers
 * as the `package_purchase` morph alias in PaymentServiceProvider and
 * is the thing `payments.payable_type`/`payable_id` point at.
 *
 * ONE purchase, MANY payment attempts. The purchase stays
 * PendingPayment across any number of failed or cancelled attempts —
 * failure belongs to the attempt, never to the aggregate. See
 * App\Package\Enums\PackagePurchaseStatus.
 *
 * The commercial terms are a snapshot of the approved proposal and are
 * immutable: `updating` refuses any change to the proposal, student,
 * amount, currency, or reference. Only `status`/`paid_at` may move, and
 * only through PackagePurchaseService.
 */
class StudentPackagePurchase extends Model implements Payable
{
    use HasUuids, LogsActivity, PreventsHardDeletion;

    /** The stable morph alias — must match PaymentServiceProvider's map. */
    public const string PAYABLE_TYPE = 'package_purchase';

    /** Commercial terms are locked once accepted; only settlement may move the record. */
    private const IMMUTABLE_ATTRIBUTES = [
        'proposal_id',
        'student_id',
        'reference',
        'amount_minor',
        'currency_id',
        'currency_code',
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $attributes = [
        'status' => 'pending_payment',
    ];

    protected $fillable = [
        'proposal_id',
        'student_id',
        'reference',
        'amount_minor',
        'currency_id',
        'currency_code',
        'status',
        'accepted_at',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PackagePurchaseStatus::class,
            'amount_minor' => 'integer',
            'accepted_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (StudentPackagePurchase $purchase): void {
            foreach (self::IMMUTABLE_ATTRIBUTES as $attribute) {
                if ($purchase->isDirty($attribute)) {
                    throw new ImmutableRecordCannotBeUpdatedException(sprintf(
                        'StudentPackagePurchase %s: "%s" is part of the accepted commercial snapshot and cannot be changed.',
                        $purchase->reference ?? $purchase->getKey(),
                        $attribute,
                    ));
                }
            }
        });
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(InstructorPackageProposal::class, 'proposal_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /** Every attempt ever made against this purchase, successful or not. */
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function scopeForStudent(Builder $query, int $studentId): Builder
    {
        return $query->where('student_id', $studentId);
    }

    public function scopePendingPayment(Builder $query): Builder
    {
        return $query->where('status', PackagePurchaseStatus::PendingPayment);
    }

    // ── Payable ───────────────────────────────────────────────────────────

    public function paymentPayableType(): string
    {
        return self::PAYABLE_TYPE;
    }

    public function paymentPayableId(): string
    {
        return (string) $this->getKey();
    }

    public function paymentAmountMinor(): int
    {
        return (int) $this->amount_minor;
    }

    public function paymentCurrencyCode(): string
    {
        return (string) $this->currency_code;
    }

    public function paymentUserId(): int
    {
        return (int) $this->student_id;
    }

    public function paymentReference(): string
    {
        return (string) $this->reference;
    }

    /**
     * Display/support context only. Deliberately no personal detail
     * beyond the student's own id — this can end up in gateway metadata
     * and in payment audit entries.
     *
     * @return array<string, mixed>
     */
    public function paymentMetadata(): array
    {
        return array_filter([
            'purchase_reference' => $this->reference,
            'proposal_id' => $this->proposal_id,
            'package_offer' => $this->proposal?->packageBenefitRule?->name,
            'subject' => $this->proposal?->subject?->name,
            'total_lessons' => $this->proposal?->total_quantity,
            'student_id' => $this->student_id,
        ], static fn (mixed $value): bool => $value !== null);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'amount_minor', 'currency_code', 'paid_at'])
            ->useLogName('student_package_purchases')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
