<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

/**
 * A booking type is a driver, mirroring Navigation link-type drivers.
 * Implement this interface and register it in BookingServiceProvider
 * to add a new appointment type — no core changes required.
 */
interface BookingTypeInterface
{
    /** Stable identifier persisted on bookings (snake_case). */
    public function key(): string;

    public function label(): string;

    /** Paid types enter the payment pipeline (BookingPaymentStatus::Pending). */
    public function isPaid(): bool;

    public function defaultDurationMinutes(): int;

    /** false = auto-confirm on request; true = instructor/admin must confirm. */
    public function requiresApproval(): bool;

    /**
     * Type-specific domain rules run by BookingValidationPipeline.
     *
     * @return list<class-string<BookingRuleInterface>>
     */
    public function rules(): array;

    /**
     * Type-specific HTTP validation rules, merged into the FormRequest
     * on top of the base booking rules (see docs/booking.md).
     *
     * @return array<string, mixed>
     */
    public function formRules(): array;
}
