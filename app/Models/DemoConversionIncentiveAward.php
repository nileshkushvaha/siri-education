<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Concerns\PreventsHardDeletion;
use Database\Factories\DemoConversionIncentiveAwardFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SRS §15.18 — one immutable row per converting paid lesson. Written
 * exclusively by DemoConversionIncentiveService; nothing ever
 * updates a row after creation (no status lifecycle, unlike
 * InstructorEarning) — a mistaken award is reversed via the
 * InstructorEarning it created (InstructorEarningService::reverse()),
 * never by mutating or deleting this record.
 */
class DemoConversionIncentiveAward extends Model
{
    /** @use HasFactory<DemoConversionIncentiveAwardFactory> */
    use HasFactory, HasUuids, PreventsHardDeletion;

    protected $fillable = [
        'demo_booking_id',
        'demo_lesson_id',
        'paid_booking_id',
        'paid_lesson_id',
        'instructor_id',
        'student_id',
        'instructor_earning_id',
        'amount_minor',
        'currency_code',
        'rule_snapshot',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'rule_snapshot' => 'array',
        ];
    }

    public function demoBooking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'demo_booking_id');
    }

    public function demoLesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class, 'demo_lesson_id');
    }

    public function paidBooking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'paid_booking_id');
    }

    public function paidLesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class, 'paid_lesson_id');
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function earning(): BelongsTo
    {
        return $this->belongsTo(InstructorEarning::class, 'instructor_earning_id');
    }
}
