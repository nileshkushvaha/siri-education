<?php

declare(strict_types=1);

namespace App\Http\Controllers\Booking;

use App\Http\Controllers\Controller;
use App\Settings\BookingSettings;
use Illuminate\Contracts\View\View;

/**
 * Renders the guest booking wizard and manage-booking shells;
 * all data flows via the guest API (manage access is authorized by
 * the booking's capability token, never by session).
 */
final class GuestBookingPageController extends Controller
{
    public function __construct(
        private readonly BookingSettings $settings,
    ) {}

    public function create(): View
    {
        return view('booking.create', [
            'turnstile' => [
                'enabled' => $this->settings->captcha_enabled,
                'siteKey' => $this->settings->turnstile_site_key,
            ],
        ]);
    }

    public function manage(string $reference): View
    {
        return view('booking.manage', ['reference' => $reference]);
    }
}
