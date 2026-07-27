<?php

declare(strict_types=1);

namespace App\Waitlist\Services;

use App\Country\Enums\CountryFeature;
use App\Country\Services\CountryFeatureResolver;
use App\Country\Services\CountryResolver;
use App\Enums\InstructorStatus;
use App\Enums\StudentStatus;
use App\Enums\WaitlistEntryStatus;
use App\Models\Booking;
use App\Models\InstructorWaitlistEntry;
use App\Models\User;
use App\Notifications\Waitlist\InstructorAvailabilityOpenedNotification;
use App\Services\AuditTrailService;
use App\Services\Notifications\NotificationIdempotencyGuard;
use App\Services\Student\StudentLifecycleService;
use App\Settings\FeatureSettings;
use App\Waitlist\Exceptions\WaitlistException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The single authoritative service for every waitlist mutation (SRS
 * §6.19/§10.28). Controllers, Livewire components, Filament
 * actions, listeners, and commands must call this service — none may
 * write `instructor_waitlist_entries` directly.
 *
 * Booking remains first-come, first-served (SRS §6.19, §10.31,
 * §10.33-4: "Waitlist notifications shall not guarantee booking").
 * There is no exclusive, time-limited offer here — processing an
 * opening notifies every currently-eligible Waiting entry for the
 * instructor, informationally, and normal booking competition governs
 * who actually gets the slot.
 */
final class WaitlistService
{
    public function __construct(
        private readonly AuditTrailService $audit,
        private readonly StudentLifecycleService $studentLifecycle,
        private readonly NotificationIdempotencyGuard $idempotency,
        private readonly CountryFeatureResolver $countryFeatures,
        private readonly CountryResolver $countryResolver,
    ) {}

    public function join(User $actor, User $instructor): InstructorWaitlistEntry
    {
        if (! $this->countryFeatures->isEnabled(CountryFeature::Waitlist, $this->countryResolver->forStudent($actor))) {
            throw new WaitlistException('Waitlists are not currently enabled.');
        }

        if (! $actor->hasRole('student')) {
            throw new AuthorizationException;
        }

        $this->studentLifecycle->assertEligibleForStudentAction($actor);

        if (! $instructor->hasRole('instructor')) {
            throw new WaitlistException('The selected instructor is not available for waitlisting.');
        }

        return DB::transaction(function () use ($actor, $instructor): InstructorWaitlistEntry {
            $freshInstructor = User::query()->whereKey($instructor->id)->firstOrFail();
            $this->assertInstructorJoinable($freshInstructor);

            $alreadyActive = InstructorWaitlistEntry::query()
                ->where('student_user_id', $actor->id)
                ->where('instructor_user_id', $freshInstructor->id)
                ->where('status', WaitlistEntryStatus::Waiting->value)
                ->exists();

            if ($alreadyActive) {
                throw new WaitlistException('You are already on this instructor\'s waitlist.');
            }

            try {
                $entry = InstructorWaitlistEntry::query()->create([
                    'student_user_id' => $actor->id,
                    'instructor_user_id' => $freshInstructor->id,
                    'status' => WaitlistEntryStatus::Waiting,
                    'active_key' => self::activeKeyFor($actor->id, $freshInstructor->id),
                    'joined_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Lost a genuine concurrent-join race against the unique
                // active_key index — the same outcome as the friendly
                // pre-check above, just reached via the database's own
                // final defense instead.
                throw new WaitlistException('You are already on this instructor\'s waitlist.');
            }

            $this->audit->logUser(
                $actor,
                'waitlist',
                'waitlist_joined',
                sprintf('Joined the waitlist for instructor #%d.', $freshInstructor->id),
                $entry,
                ['instructor_user_id' => $freshInstructor->id],
            );

            return $entry;
        });
    }

    public function leave(User $actor, InstructorWaitlistEntry $entry): InstructorWaitlistEntry
    {
        if ($actor->id !== $entry->student_user_id) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $entry): InstructorWaitlistEntry {
            $locked = InstructorWaitlistEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            // Idempotent: a repeated withdraw request (double click,
            // retried request) on an already-terminal entry is a safe
            // no-op, never an error and never a second audit entry.
            if ($locked->status !== WaitlistEntryStatus::Waiting) {
                return $locked;
            }

            $locked->forceFill([
                'status' => WaitlistEntryStatus::Withdrawn,
                'active_key' => null,
                'withdrawn_at' => now(),
            ])->save();

            $this->audit->logUser(
                $actor,
                'waitlist',
                'waitlist_withdrawn',
                sprintf('Withdrew from the waitlist for instructor #%d.', $locked->instructor_user_id),
                $locked,
                ['instructor_user_id' => $locked->instructor_user_id],
            );

            return $locked;
        });
    }

    /**
     * SRS §10.28/§10.33-4: identifies every currently-eligible Waiting
     * entry for this instructor and notifies each one — informational
     * only, never an exclusive reservation. $reason/$triggerId form
     * the per-entry notification idempotency key, so a redelivered
     * event (or two concurrent processors reacting to the same
     * opening) can never double-notify, while a genuinely distinct
     * later opening legitimately notifies again.
     *
     * @return int number of entries actually notified this call
     */
    public function processAvailabilityOpening(User $instructor, string $reason, string $triggerId): int
    {
        if (! app(FeatureSettings::class)->waitlist_enabled) {
            return 0;
        }

        $eligibleEntries = DB::transaction(function () use ($instructor): array {
            $freshInstructor = User::query()->whereKey($instructor->id)->first();

            if ($freshInstructor === null || ! $this->isInstructorCurrentlyBookable($freshInstructor)) {
                return [];
            }

            $entries = InstructorWaitlistEntry::query()
                ->where('instructor_user_id', $freshInstructor->id)
                ->where('status', WaitlistEntryStatus::Waiting->value)
                ->orderBy('joined_at')
                ->orderBy('id')
                ->with('student.profile')
                ->lockForUpdate()
                ->get();

            $eligible = [];

            foreach ($entries as $entry) {
                $student = $entry->student;

                if ($student === null || ! $this->isStudentCurrentlyEligible($student)) {
                    $entry->forceFill([
                        'status' => WaitlistEntryStatus::Ineligible,
                        'active_key' => null,
                        'ineligible_at' => now(),
                    ])->save();

                    continue;
                }

                $eligible[] = $entry;
            }

            return $eligible;
        });

        $notified = 0;

        // Dispatched strictly after the locking/eligibility transaction
        // above has committed — a notification must never fire against
        // a transition that could still roll back.
        foreach ($eligibleEntries as $entry) {
            $claimed = $this->idempotency->once(
                sprintf('waitlist-notify:%d:%s:%s', $entry->id, $reason, $triggerId),
                InstructorAvailabilityOpenedNotification::class,
                function () use ($entry, $instructor): void {
                    $entry->student->notify(new InstructorAvailabilityOpenedNotification(
                        $instructor->id,
                        $instructor->name,
                        $instructor->slug,
                    ));
                    $entry->forceFill(['notified_at' => now()])->save();
                },
            );

            if ($claimed) {
                $notified++;
            }
        }

        return $notified;
    }

    /**
     * SRS §10.31: "Waitlist notifications shall not guarantee
     * booking" — this only closes out THIS student's own entry once
     * their own booking with this instructor is authoritatively
     * confirmed. It never touches any other student's entry, never
     * creates a booking, and a failed/unconfirmed booking attempt
     * never reaches here at all (the caller only fires this from the
     * existing BookingConfirmed event).
     */
    public function fulfillForBooking(Booking $booking): void
    {
        if ($booking->student_id === null || $booking->instructor_id === null) {
            return;
        }

        DB::transaction(function () use ($booking): void {
            $entry = InstructorWaitlistEntry::query()
                ->where('student_user_id', $booking->student_id)
                ->where('instructor_user_id', $booking->instructor_id)
                ->where('status', WaitlistEntryStatus::Waiting->value)
                ->lockForUpdate()
                ->first();

            if ($entry === null) {
                return;
            }

            $entry->forceFill([
                'status' => WaitlistEntryStatus::Fulfilled,
                'active_key' => null,
                'fulfilled_at' => now(),
                'fulfilled_booking_id' => $booking->id,
            ])->save();

            $this->audit->logSystem(
                'waitlist',
                'waitlist_fulfilled',
                sprintf('Waitlist entry #%d fulfilled by booking %s.', $entry->id, $booking->reference),
                $entry,
                ['instructor_user_id' => $booking->instructor_id, 'booking_id' => $booking->id],
            );
        });
    }

    public static function activeKeyFor(int $studentId, int $instructorId): string
    {
        return sprintf('%d-%d', $studentId, $instructorId);
    }

    private function assertInstructorJoinable(User $instructor): void
    {
        $status = $instructor->profile?->instructor_status;

        if ($status === null || ! in_array($status, InstructorStatus::publiclyVisible(), true)) {
            throw new WaitlistException('The selected instructor is not available for waitlisting.');
        }
    }

    /** Stricter than the join-time gate: the processor only fires when the instructor is genuinely bookable right now (Approved/Active), never merely publicly visible (e.g. still on Vacation). */
    private function isInstructorCurrentlyBookable(User $instructor): bool
    {
        if (! $instructor->hasRole('instructor')) {
            return false;
        }

        $status = $instructor->profile?->instructor_status;

        return $status !== null && in_array($status, InstructorStatus::bookable(), true);
    }

    private function isStudentCurrentlyEligible(User $student): bool
    {
        if (! $student->hasRole('student')) {
            return false;
        }

        return $student->profile?->student_status === StudentStatus::Active;
    }
}
