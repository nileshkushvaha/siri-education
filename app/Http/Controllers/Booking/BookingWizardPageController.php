<?php

declare(strict_types=1);

namespace App\Http\Controllers\Booking;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * Renders the authenticated-student booking wizard shell —
 * renamed from the pre-authenticated-only "guest" page
 * controller; the route itself requires `auth` middleware.
 * All data flows via the BookingWizard Livewire
 * component embedded in the view.
 */
final class BookingWizardPageController extends Controller
{
    public function create(): View
    {
        return view('booking.create');
    }
}
