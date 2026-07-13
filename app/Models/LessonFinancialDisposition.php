<?php

declare(strict_types=1);

namespace App\Models;

use App\Earnings\Enums\LessonFinancialDispositionStatus;
use App\Earnings\Enums\LessonInstructorDisposition;
use App\Earnings\Enums\LessonStudentDisposition;
use App\Lessons\Enums\LessonOutcome;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One financial-decision record per lesson (unique lesson_id).
 * Written exclusively by LessonFinancialDispositionService — it
 * classifies; it never credits, refunds, reverses, or creates
 * earnings. Re-evaluations append the previous snapshot to `history`
 * and bump `version`; rows are never deleted.
 */
class LessonFinancialDisposition extends Model
{
    use HasUuids;

    protected $fillable = [
        'lesson_id',
        'booking_id',
        'outcome',
        'student_disposition',
        'instructor_disposition',
        'processing_status',
        'reason_code',
        'instructor_earning_id',
        'payment_reference',
        'admin_hold',
        'version',
        'history',
        'refund_ledger_entry_id',
        'refund_executed_at',
        'evaluated_at',
        'resolved_at',
        'resolved_by',
        'resolution_reason',
    ];

    protected function casts(): array
    {
        return [
            'outcome' => LessonOutcome::class,
            'student_disposition' => LessonStudentDisposition::class,
            'instructor_disposition' => LessonInstructorDisposition::class,
            'processing_status' => LessonFinancialDispositionStatus::class,
            'admin_hold' => 'boolean',
            'version' => 'integer',
            'history' => 'array',
            'refund_executed_at' => 'immutable_datetime',
            'evaluated_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function earning(): BelongsTo
    {
        return $this->belongsTo(InstructorEarning::class, 'instructor_earning_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function refundLedgerEntry(): BelongsTo
    {
        return $this->belongsTo(WalletLedgerEntry::class, 'refund_ledger_entry_id');
    }

    /** @return array<string, mixed> */
    public function snapshotForHistory(): array
    {
        return [
            'version' => $this->version,
            'outcome' => $this->outcome->value,
            'student_disposition' => $this->student_disposition->value,
            'instructor_disposition' => $this->instructor_disposition->value,
            'processing_status' => $this->processing_status->value,
            'reason_code' => $this->reason_code,
            'instructor_earning_id' => $this->instructor_earning_id,
            'admin_hold' => $this->admin_hold,
            'evaluated_at' => $this->evaluated_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'resolved_by' => $this->resolved_by,
            'resolution_reason' => $this->resolution_reason,
        ];
    }
}
