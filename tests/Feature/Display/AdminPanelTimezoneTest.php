<?php

declare(strict_types=1);

namespace Tests\Feature\Display;

use App\Models\User;
use App\Models\UserProfile;
use App\Support\Timezone\ViewerDateTime;
use Carbon\CarbonImmutable;
use Filament\Schemas\Components\StateCasts\DateTimeStateCast;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * TZ-4 — TZ-AUD-007 (admin display) and TZ-AUD-009 (admin input).
 *
 * Both are closed by one registration in AdminPanelProvider rather than
 * by editing 169 columns:
 *
 *     FilamentTimezone::set(fn () => ViewerDateTime::timezoneFor())
 *
 * Filament v5.6 threads that manager through
 * `CanFormatState::getTimezone()` (columns) and
 * `DateTimePicker::getTimezone()` (form input), and — importantly —
 * only for components that actually carry a TIME. Date-only columns and
 * DatePickers keep falling back to `config('app.timezone')`, which is
 * what stops a birthday or a settlement period boundary from sliding a
 * day.
 *
 * The input half matters as much as the display half: Filament's own
 * DateTimeStateCast converts the stored UTC value INTO the manager's
 * timezone when populating a form, and shifts the typed wall clock back
 * OUT of it on save. With the manager left at UTC — the state before
 * this phase — an admin typing "09:00" had 09:00 stored verbatim as UTC.
 */
class AdminPanelTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private function admin(?string $timezone): User
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $admin->id], ['timezone' => $timezone]);

        return $admin->fresh();
    }

    // ── The manager resolves per request, per user ──────────────────────

    public function test_the_panel_registers_a_lazy_per_viewer_timezone(): void
    {
        // The panel boots before anyone authenticates, so a resolved
        // STRING would freeze whoever happened to be first (or nobody).
        // TimezoneManager re-evaluates a Closure on every get().
        $this->get('/admin');

        Auth::login($this->admin('Asia/Kolkata'));
        $this->assertSame('Asia/Kolkata', FilamentTimezone::get());

        Auth::login($this->admin('America/New_York'));
        $this->assertSame('America/New_York', FilamentTimezone::get());
    }

    public function test_an_admin_with_no_explicit_timezone_falls_through_the_canonical_chain(): void
    {
        $this->get('/admin');
        Auth::login($this->admin('Invalid/Zone'));

        // Never throws, never leaks a bad value into a column.
        $this->assertSame(ViewerDateTime::timezoneFor(Auth::user()), FilamentTimezone::get());
        $this->assertContains(FilamentTimezone::get(), timezone_identifiers_list());
    }

    // ── TZ-AUD-009 · admin wall-clock input round-trips ─────────────────

    public function test_a_datetime_picker_stores_the_admins_wall_clock_as_the_right_instant(): void
    {
        $cast = new DateTimeStateCast('Y-m-d H:i:s', 'Y-m-d H:i:s', 'Europe/London');

        // Admin types 09:00 while working in London (BST, +01:00).
        $this->assertSame('2026-08-15 08:00:00', $cast->get('2026-08-15 09:00:00'));

        // …and reading it back shows them 09:00 again, not 08:00.
        $this->assertSame('2026-08-15 09:00:00', $cast->set('2026-08-15 08:00:00'));
    }

    public function test_the_old_utc_manager_is_what_stored_the_wrong_instant(): void
    {
        // Evidence the fixture discriminates: with the manager at UTC —
        // the pre-TZ-4 state — the same keystrokes produce a different
        // stored instant, one hour out.
        $utc = new DateTimeStateCast('Y-m-d H:i:s', 'Y-m-d H:i:s', 'UTC');
        $london = new DateTimeStateCast('Y-m-d H:i:s', 'Y-m-d H:i:s', 'Europe/London');

        $this->assertSame('2026-08-15 09:00:00', $utc->get('2026-08-15 09:00:00'));
        $this->assertNotSame($utc->get('2026-08-15 09:00:00'), $london->get('2026-08-15 09:00:00'));
    }

    public function test_action_code_parsing_the_dehydrated_value_as_utc_remains_correct(): void
    {
        // BookingsTable and the campaign builders parse the picker's
        // output with an explicit 'UTC'. That is now RIGHT, because the
        // state cast already converted — a second conversion here would
        // double-shift. This pins that so the explicit 'UTC' is not
        // "helpfully" removed later.
        $cast = new DateTimeStateCast('Y-m-d H:i:s', 'Y-m-d H:i:s', 'Europe/London');
        $dehydrated = $cast->get('2026-08-15 09:00:00');

        $instant = CarbonImmutable::parse($dehydrated, 'UTC');

        $this->assertSame('2026-08-15T08:00:00+00:00', $instant->toIso8601String());
        $this->assertSame('09:00', $instant->setTimezone('Europe/London')->format('H:i'));
    }

    // ── Date-only values stay put ───────────────────────────────────────

    public function test_date_only_components_are_not_shifted_by_the_manager(): void
    {
        // Filament only consults FilamentTimezone when a component has a
        // time part. A date-only value has no timezone to convert from,
        // and shifting it is how a birthday moves a day for viewers west
        // of UTC.
        $dateOnly = new DateTimeStateCast('Y-m-d', 'Y-m-d', config('app.timezone'));

        $this->assertSame('1990-04-12', $dateOnly->get('1990-04-12'));
        $this->assertSame('1990-04-12', $dateOnly->set('1990-04-12'));
    }
}
