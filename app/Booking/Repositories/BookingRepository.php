<?php

declare(strict_types=1);

namespace App\Booking\Repositories;

use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\Enums\BookingActivityAction;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\MeetingStatus;
use App\Booking\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\BookingActivity;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class BookingRepository implements BookingRepositoryInterface
{
    private const int LOCK_TIMEOUT_SECONDS = 10;

    public function create(CreateBookingData $data, BookingStatus $status, array $attributes = []): Booking
    {
        $plainToken = $data->isGuest() ? Str::random(64) : null;

        $booking = Booking::create([
            'attendee_id' => $data->attendeeId,
            'guest_name' => $data->guestName,
            'guest_email' => $data->guestEmail,
            'guest_phone' => $data->guestPhone,
            // Only the hash is stored — the plain token is exposed once
            // via $booking->plainManageToken on this instance.
            'manage_token' => $plainToken !== null ? hash('sha256', $plainToken) : null,
            'host_id' => $data->hostId,
            'status' => $status,
            'location_type' => $data->locationType,
            'starts_at' => $data->startsAt,
            'ends_at' => $data->endsAt(),
            'timezone' => $data->timezone,
            'notes' => $data->notes,
            'meta' => $data->meta ?: null,
            ...$attributes,
        ]);

        $booking->plainManageToken = $plainToken;

        return $booking;
    }

    public function findByReference(string $reference): ?Booking
    {
        return Booking::query()->where('reference', $reference)->first();
    }

    public function find(string $id): ?Booking
    {
        return Booking::find($id);
    }

    public function findOrFail(string $id): Booking
    {
        return Booking::findOrFail($id);
    }

    public function transitionStatus(Booking $booking, BookingStatus $status, array $attributes = []): Booking
    {
        $booking->fill(['status' => $status, ...$attributes]);
        $booking->save();

        return $booking->refresh();
    }

    public function reschedule(Booking $booking, CarbonImmutable $startsAt, CarbonImmutable $endsAt): Booking
    {
        $booking->fill(['starts_at' => $startsAt, 'ends_at' => $endsAt]);
        $booking->save();

        return $booking->refresh();
    }

    public function hasOverlap(
        int $hostId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?string $ignoreBookingId = null,
        ?string $sharedSlotTypeKey = null,
        int $bufferMinutes = 0,
    ): bool {
        $paddedStart = $startsAt->subMinutes(max(0, $bufferMinutes));
        $paddedEnd = $endsAt->addMinutes(max(0, $bufferMinutes));

        return Booking::query()
            ->forHost($hostId)
            ->overlapping($paddedStart, $paddedEnd, $ignoreBookingId)
            ->when($sharedSlotTypeKey, fn (Builder $q, string $key) => $q->whereNot(
                fn (Builder $shared) => $shared
                    ->where('starts_at', $startsAt)
                    ->where('ends_at', $endsAt)
                    ->whereHas('type', fn (Builder $type) => $type->where('key', $key)),
            ))
            ->lockForUpdate()
            ->exists();
    }

    public function duplicateExists(CreateBookingData $data): bool
    {
        return Booking::query()
            ->active()
            ->when(
                $data->isGuest(),
                fn (Builder $q) => $q->where('guest_email', $data->guestEmail),
                fn (Builder $q) => $q->forAttendee($data->attendeeId),
            )
            ->forHost($data->hostId)
            ->where('starts_at', $data->startsAt)
            ->whereHas('type', fn (Builder $type) => $type->where('key', $data->typeKey))
            ->lockForUpdate()
            ->exists();
    }

    public function activeUpcomingCountForGuestEmail(string $email): int
    {
        return Booking::query()
            ->active()
            ->upcoming()
            ->where('guest_email', $email)
            ->count();
    }

    public function attendeeCountForSlot(int $hostId, string $typeKey, CarbonImmutable $startsAt): int
    {
        return Booking::query()
            ->active()
            ->forHost($hostId)
            ->where('starts_at', $startsAt)
            ->whereHas('type', fn (Builder $type) => $type->where('key', $typeKey))
            ->lockForUpdate()
            ->count();
    }

    public function activeCountForDay(int $hostId, CarbonImmutable $day, ?string $ignoreBookingId = null): int
    {
        return Booking::query()
            ->active()
            ->forHost($hostId)
            ->whereBetween('starts_at', [$day->startOfDay(), $day->endOfDay()])
            ->when($ignoreBookingId, fn (Builder $q) => $q->whereKeyNot($ignoreBookingId))
            ->lockForUpdate()
            ->count();
    }

    public function activeUpcomingCountForHost(int $hostId): int
    {
        return Booking::query()
            ->active()
            ->upcoming()
            ->forHost($hostId)
            ->count();
    }

    public function activeBetween(int $hostId, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Booking::query()
            ->active()
            ->forHost($hostId)
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->get();
    }

    public function upcomingForUser(int $userId): Collection
    {
        return Booking::query()
            ->active()
            ->upcoming()
            ->where(fn (Builder $q) => $q->where('attendee_id', $userId)->orWhere('host_id', $userId))
            ->with(['type', 'host', 'meeting'])
            ->orderBy('starts_at')
            ->get();
    }

    /** withTrashed() — a student's own booking history remains visible even after an admin archives one of their terminal bookings (Phase 17U.1); archived rows are never upcoming or actionable regardless. */
    public function paginatedForUser(int $userId, int $perPage = 15, ?BookingStatus $status = null): LengthAwarePaginator
    {
        return Booking::withTrashed()
            ->forAttendee($userId)
            ->with(['type', 'host', 'meeting'])
            ->when($status, fn (Builder $q) => $q->withStatus($status))
            ->orderByDesc('starts_at')
            ->paginate($perPage);
    }

    public function paginatedPaymentsForUser(int $userId, int $perPage = 15): LengthAwarePaginator
    {
        return Booking::query()
            ->forAttendee($userId)
            ->whereNot('payment_status', BookingPaymentStatus::NotRequired)
            ->with(['type', 'host'])
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function attendanceStatsForUser(int $userId): object
    {
        return Booking::query()
            ->forAttendee($userId)
            ->selectRaw(
                'SUM(status = ?) as completed, SUM(status = ?) as no_show, SUM(status = ?) as cancelled, COUNT(*) as total',
                [BookingStatus::Completed->value, BookingStatus::NoShow->value, BookingStatus::Cancelled->value],
            )
            ->first();
    }

    public function attendanceHistoryForUser(int $userId, int $limit = 50): Collection
    {
        return Booking::query()
            ->forAttendee($userId)
            ->whereIn('status', [BookingStatus::Completed, BookingStatus::NoShow])
            ->with(['type', 'host'])
            ->orderByDesc('starts_at')
            ->limit($limit)
            ->get();
    }

    public function progressStatsForUser(int $userId): object
    {
        return Booking::query()
            ->forAttendee($userId)
            ->where('status', BookingStatus::Completed)
            ->selectRaw(
                'COUNT(*) as completed_sessions, COALESCE(SUM(TIMESTAMPDIFF(MINUTE, starts_at, ends_at)), 0) / 60.0 as total_hours',
            )
            ->first();
    }

    public function subjectBreakdownForUser(int $userId): Collection
    {
        return Booking::query()
            ->forAttendee($userId)
            ->where('status', BookingStatus::Completed)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.subject')) IS NOT NULL")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.subject')) as subject, COUNT(*) as sessions")
            ->groupBy('subject')
            ->orderByDesc('sessions')
            ->get();
    }

    public function previousHostsForAttendee(int $attendeeId): Collection
    {
        $hostIds = Booking::query()
            ->forAttendee($attendeeId)
            ->where('status', '!=', BookingStatus::Cancelled)
            ->selectRaw('host_id, MAX(starts_at) as last_starts_at')
            ->groupBy('host_id')
            ->orderByDesc('last_starts_at')
            ->pluck('host_id');

        return User::query()
            ->whereIn('id', $hostIds)
            ->get()
            ->sortBy(fn (User $user): int|false => $hostIds->search($user->id))
            ->values();
    }

    public function updatePaymentStatus(Booking $booking, BookingPaymentStatus $status, ?string $reference = null): Booking
    {
        $booking->fill([
            'payment_status' => $status,
            'payment_reference' => $reference ?? $booking->payment_reference,
        ]);
        $booking->save();

        return $booking->refresh();
    }

    public function findByPaymentReference(string $reference): ?Booking
    {
        return Booking::query()->where('payment_reference', $reference)->first();
    }

    public function clearReservation(Booking $booking): Booking
    {
        $booking->fill(['reserved_until' => null]);
        $booking->save();

        return $booking->refresh();
    }

    public function updateMeeting(Booking $booking, MeetingStatus $status, array $attributes = []): Booking
    {
        $booking->fill(['meeting_status' => $status, ...$attributes]);
        $booking->save();

        return $booking->refresh();
    }

    public function expiredReservations(): Collection
    {
        return Booking::query()
            ->withStatus(BookingStatus::Pending)
            ->whereIn('payment_status', [BookingPaymentStatus::Pending, BookingPaymentStatus::Failed])
            ->whereNotNull('reserved_until')
            ->where('reserved_until', '<', now())
            ->get();
    }

    public function logActivity(
        Booking $booking,
        BookingActivityAction $action,
        BookingActor $actorType,
        ?int $actorId = null,
        ?BookingStatus $from = null,
        ?BookingStatus $to = null,
        array $meta = [],
    ): void {
        BookingActivity::create([
            'booking_id' => $booking->id,
            'action' => $action,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'status_from' => $from,
            'status_to' => $to,
            'meta' => $meta ?: null,
            'created_at' => now(),
        ]);
    }

    public function withHostLock(int $hostId, Closure $callback): mixed
    {
        $name = sprintf('booking:host:%d', $hostId);

        $granted = DB::selectOne('SELECT GET_LOCK(?, ?) AS granted', [$name, self::LOCK_TIMEOUT_SECONDS]);

        if ((int) ($granted->granted ?? 0) !== 1) {
            throw new BookingException(sprintf('Could not acquire booking lock for host #%d.', $hostId));
        }

        try {
            return $callback();
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
        }
    }
}
