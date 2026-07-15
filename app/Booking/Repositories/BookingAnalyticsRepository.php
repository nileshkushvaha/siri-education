<?php

declare(strict_types=1);

namespace App\Booking\Repositories;

use App\Booking\Contracts\BookingAnalyticsRepositoryInterface;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Types\FreeDemoType;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class BookingAnalyticsRepository implements BookingAnalyticsRepositoryInterface
{
    public function overview(CarbonImmutable $from, CarbonImmutable $to): object
    {
        return Booking::query()
            ->join('booking_types', 'booking_types.id', '=', 'bookings.booking_type_id')
            ->whereBetween('bookings.created_at', [$from, $to])
            ->selectRaw(
                'COUNT(*) as total,
                 COALESCE(SUM(booking_types.`key` = ?), 0) as demo_requests,
                 COALESCE(SUM(bookings.status = ?), 0) as cancelled,
                 COALESCE(SUM(bookings.status = ?), 0) as completed,
                 SUM(CASE WHEN bookings.payment_status = ? THEN bookings.price ELSE 0 END) as revenue,
                 SUM(CASE WHEN bookings.payment_status = ? THEN bookings.price ELSE 0 END) as refunded',
                [
                    FreeDemoType::KEY,
                    BookingStatus::Cancelled->value,
                    BookingStatus::Completed->value,
                    BookingPaymentStatus::Paid->value,
                    BookingPaymentStatus::Refunded->value,
                ],
            )
            ->first();
    }

    public function conversion(CarbonImmutable $from, CarbonImmutable $to): object
    {
        // Booker identity: every booking has an authenticated student_id
        // (Phase 17U.3 — no guest booking concept exists).
        return (object) (array) DB::selectOne(
            'SELECT COUNT(DISTINCT d.booker) AS demo_bookers,
                    COUNT(DISTINCT CASE WHEN p.id IS NOT NULL THEN d.booker END) AS converted
             FROM (
                 SELECT b.student_id AS booker, b.created_at
                 FROM bookings b
                 JOIN booking_types t ON t.id = b.booking_type_id
                 WHERE t.`key` = ?
                   AND b.deleted_at IS NULL
                   AND b.created_at BETWEEN ? AND ?
             ) d
             LEFT JOIN (
                 SELECT b.id, b.student_id AS booker, b.created_at
                 FROM bookings b
                 JOIN booking_types t ON t.id = b.booking_type_id
                 WHERE t.is_paid = 1 AND b.deleted_at IS NULL
             ) p ON p.booker = d.booker AND p.created_at >= d.created_at',
            [FreeDemoType::KEY, $from, $to],
        );
    }

    public function trendPerDay(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Booking::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw(
                'DATE(created_at) as day,
                 COUNT(*) as bookings,
                 SUM(CASE WHEN payment_status = ? THEN price ELSE 0 END) as revenue',
                [BookingPaymentStatus::Paid->value],
            )
            ->groupBy('day')
            ->orderBy('day')
            ->get();
    }

    public function popularSubjects(CarbonImmutable $from, CarbonImmutable $to, int $limit = 8): Collection
    {
        return Booking::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.subject')) IS NOT NULL")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.subject')) as subject, COUNT(*) as bookings")
            ->groupBy('subject')
            ->orderByDesc('bookings')
            ->limit($limit)
            ->get();
    }

    public function popularHours(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Booking::query()
            ->whereBetween('starts_at', [$from, $to])
            ->where('status', '!=', BookingStatus::Cancelled)
            ->selectRaw('HOUR(starts_at) as hour, COUNT(*) as bookings')
            ->groupBy('hour')
            ->orderBy('hour')
            ->get();
    }

    public function bookedMinutesPerTeacher(CarbonImmutable $from, CarbonImmutable $to, int $limit = 10): Collection
    {
        return Booking::query()
            ->join('users', 'users.id', '=', 'bookings.instructor_id')
            ->whereBetween('bookings.starts_at', [$from, $to])
            ->whereIn('bookings.status', [BookingStatus::Confirmed, BookingStatus::Completed])
            ->selectRaw(
                'bookings.instructor_id,
                 users.name,
                 COALESCE(SUM(TIMESTAMPDIFF(MINUTE, bookings.starts_at, bookings.ends_at)), 0) as minutes',
            )
            ->groupBy('bookings.instructor_id', 'users.name')
            ->orderByDesc('minutes')
            ->limit($limit)
            ->get();
    }

    public function weeklyAvailableMinutes(array $teacherIds): Collection
    {
        if ($teacherIds === []) {
            return new Collection;
        }

        return DB::table('teacher_availability')
            ->whereIn('teacher_id', $teacherIds)
            ->where('is_active', true)
            ->selectRaw('teacher_id, COALESCE(SUM(TIMESTAMPDIFF(MINUTE, CONCAT("2000-01-01 ", start_time), CONCAT("2000-01-01 ", end_time))), 0) as minutes')
            ->groupBy('teacher_id')
            ->get();
    }
}
