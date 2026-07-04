<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Booking;

use App\Booking\Contracts\BookingAnalyticsServiceInterface;
use App\Models\Booking;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * Booked vs available hours per teacher (last 30 days). Available
 * hours approximate the weekly schedule × weeks — leave and holidays
 * are not subtracted.
 */
class TeacherUtilizationWidget extends Widget
{
    protected string $view = 'filament.widgets.booking.teacher-utilization';

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('viewAny', Booking::class) ?? false;
    }

    /** @return Collection<int, array{teacher: string, booked_hours: float, available_hours: float, utilization: float}> */
    public function getRows(): Collection
    {
        return app(BookingAnalyticsServiceInterface::class)->teacherUtilization(
            now()->subDays(29)->startOfDay()->toImmutable(),
            now()->toImmutable(),
        );
    }
}
