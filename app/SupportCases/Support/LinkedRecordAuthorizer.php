<?php

declare(strict_types=1);

namespace App\SupportCases\Support;

use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\InstructorWithdrawalRequest;
use App\Models\Invoice;
use App\Models\Lesson;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use App\SupportCases\Exceptions\UnauthorizedLinkedRecordException;
use Illuminate\Database\Eloquent\Model;

/**
 * Enforces SRS §25.41/§25.42/§25.44: "A requester may link only
 * records they are authorized to view" — a support case must never
 * become a side channel for reading another user's booking, payment,
 * invoice, wallet, or lesson data. Every allowed link type is checked
 * against an ownership predicate before the case is allowed to store
 * it; an admin-created case (no requester ownership constraint) skips
 * this check entirely.
 *
 * @phpstan-type LinkedRecordType class-string<Model>
 */
final class LinkedRecordAuthorizer
{
    /** @var array<class-string<Model>, string> */
    private const array ALLOWED_TYPES = [
        Booking::class => 'booking',
        Lesson::class => 'lesson',
        BookingPayment::class => 'payment',
        Invoice::class => 'invoice',
        WalletLedgerEntry::class => 'wallet_transaction',
        InstructorWithdrawalRequest::class => 'withdrawal',
        User::class => 'instructor',
    ];

    /**
     * @return array{0: class-string<Model>, 1: string}|null
     */
    public function authorize(User $requester, ?string $type, ?string $id, bool $skipOwnershipCheck = false): ?array
    {
        if ($type === null || $id === null) {
            return null;
        }

        if (! array_key_exists($type, self::ALLOWED_TYPES)) {
            throw new UnauthorizedLinkedRecordException("Unsupported linked record type [{$type}].");
        }

        $record = $type::query()->find($id);

        if ($record === null) {
            throw new UnauthorizedLinkedRecordException('Linked record not found.');
        }

        if (! $skipOwnershipCheck && ! $this->owns($requester, $record)) {
            throw new UnauthorizedLinkedRecordException('You are not authorized to link this record.');
        }

        return [$type, (string) $record->getKey()];
    }

    private function owns(User $requester, Model $record): bool
    {
        return match ($record::class) {
            Booking::class, Lesson::class => $record->student_id === $requester->id || $record->instructor_id === $requester->id,
            BookingPayment::class, Invoice::class, WalletLedgerEntry::class => $record->user_id === $requester->id,
            InstructorWithdrawalRequest::class => $record->instructor_id === $requester->id,
            User::class => $this->hasInteractedWithInstructor($requester, $record),
            default => false,
        };
    }

    private function hasInteractedWithInstructor(User $requester, User $instructor): bool
    {
        if ($requester->id === $instructor->id) {
            return true;
        }

        return Booking::query()
            ->where(function ($query) use ($requester, $instructor): void {
                $query->where('student_id', $requester->id)->where('instructor_id', $instructor->id);
            })
            ->orWhere(function ($query) use ($requester, $instructor): void {
                $query->where('instructor_id', $requester->id)->where('student_id', $instructor->id);
            })
            ->exists();
    }
}
