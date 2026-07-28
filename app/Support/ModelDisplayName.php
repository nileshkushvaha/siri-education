<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\InstructorWithdrawalRequest;
use App\Models\Invoice;
use App\Models\Lesson;
use App\Models\Message;
use App\Models\StudentLearningPlan;
use App\Models\User;
use App\Models\WalletLedgerEntry;

/** Friendly labels for model classes shown in admin-facing viewers (audit trail, linked records, conversation context). */
final class ModelDisplayName
{
    private const array LABELS = [
        Booking::class => 'Booking',
        Lesson::class => 'Lesson',
        BookingPayment::class => 'Payment',
        Invoice::class => 'Invoice',
        WalletLedgerEntry::class => 'Wallet Transaction',
        InstructorWithdrawalRequest::class => 'Withdrawal',
        Message::class => 'Message',
        StudentLearningPlan::class => 'Learning Plan',
        User::class => 'User',
    ];

    public static function for(?string $class): string
    {
        if ($class === null || $class === '') {
            return '—';
        }

        return self::LABELS[$class] ?? class_basename($class);
    }
}
