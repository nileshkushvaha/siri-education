<?php

declare(strict_types=1);

namespace App\Http\Controllers\Booking;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * Renders the authenticated-student booking wizard shell (Phase
 * 17U.3 — renamed from the pre-authenticated-only "guest" page
 * controller; the route itself has required `auth` middleware since
 * Phase 10.2C-Fix). All data flows via the BookingWizard Livewire
 * component embedded in the view.
 */
final class BookingWizardPageController extends Controller
{
    public function create(): View
    {
        return view('booking.create');
    }
}
