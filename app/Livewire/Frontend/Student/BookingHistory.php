<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Student;

use App\Booking\Contracts\BookingMeetingServiceInterface;
use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Booking\Enums\BookingStatus;
use App\Services\Student\StudentScheduleService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The student's booking LIST (`/dashboard/my-bookings`).
 *
 * Detail, reschedule, cancel and payment live on their own page —
 * see BookingDetail — so this component only ever reads.
 */
final class BookingHistory extends Component
{
    use WithPagination;

    /**
     * Page sizes offered in the "per page" control. Public because the
     * detail page validates a returning `per_page` against the same list.
     */
    public const PER_PAGE_OPTIONS = [10, 25, 50];

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    #[Url(as: 'per_page', except: 10)]
    public int $perPage = 10;

    /** Session key holding the last list state, for back links that arrive without one. */
    public const LIST_STATE_SESSION_KEY = 'student.bookings.list_state';

    private StudentBookingServiceInterface $bookings;

    private BookingMeetingServiceInterface $meetings;

    private StudentScheduleService $schedule;

    public function boot(StudentBookingServiceInterface $bookings, BookingMeetingServiceInterface $meetings, StudentScheduleService $schedule): void
    {
        $this->bookings = $bookings;
        $this->meetings = $meetings;
        $this->schedule = $schedule;
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingPerPage(): void
    {
        $this->resetPage();
    }

    public function setStatusFilter(string $status): void
    {
        $this->statusFilter = $status;
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->statusFilter = '';
        $this->resetPage();
    }

    public function render(): View
    {
        // A URL-supplied filter/page-size is never trusted: an unknown
        // status falls back to "all", an unlisted page size to the default.
        $status = BookingStatus::tryFrom($this->statusFilter);

        if ($status === null) {
            $this->statusFilter = '';
        }

        if (! in_array($this->perPage, self::PER_PAGE_OPTIONS, true)) {
            $this->perPage = self::PER_PAGE_OPTIONS[0];
        }

        $history = $this->bookings->bookingHistory(auth()->user(), $this->perPage, $status);

        return view('livewire.frontend.student.booking-history', [
            'history' => $history,
            // The soonest lesson not yet ended, pinned above the list
            // whatever the filter or page — a student on a long recurring
            // schedule must never dig for today's join link.
            'nextUp' => $this->schedule->nextUp(auth()->user()),
            // Join state per listed row, one lifecycle read for the page.
            'joinStates' => $this->meetings->studentJoinStatesFor($history->getCollection(), auth()->user()),
            'statuses' => BookingStatus::cases(),
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            // Carried into every detail link so the page's back button
            // returns to this exact filter and page, not the top of the list.
            'listQuery' => $this->rememberListState($history->currentPage()),
        ]);
    }

    /**
     * The current filter/page-size/page, both handed to the row links and
     * stashed in the session.
     *
     * The link carries it so back works on a shared or reloaded URL; the
     * session covers every other way into a booking (the Payments page,
     * the dashboard's next-lesson card, a notification email), where the
     * link has no list state to carry but the student still expects to
     * come back to the list they were last looking at.
     *
     * @return array<string, string|int>
     */
    private function rememberListState(int $currentPage): array
    {
        $state = array_filter([
            'status' => $this->statusFilter,
            'per_page' => $this->perPage === self::PER_PAGE_OPTIONS[0] ? null : $this->perPage,
            'page' => $currentPage > 1 ? $currentPage : null,
        ]);

        session([self::LIST_STATE_SESSION_KEY => $state]);

        return $state;
    }
}
