<?php

declare(strict_types=1);

namespace App\Platform\Audit;

use App\Models\AcademicLevel;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\StudentLessonPrice;
use App\Models\Subject;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Payment\PaymentWebhookSignatureService;
use App\Settings\PaymentGatewaySettings;
use App\Support\Timezone\IanaTimezone;
use Illuminate\Support\Collection;

/**
 * Read-only audit of the DATA a working platform depends on but no test
 * can see: gateway secrets, price-matrix coverage, currencies, time zones
 * and instructor availability. Every defect a customer hit on 4 Sep 2026
 * (wallet webhook secret missing, no price row for the student's country
 * and level) is something this would have listed before they did.
 *
 * It never writes. Run it against staging or production after every
 * configuration change and before every release:
 *
 *     php artisan platform:audit-config
 */
final class PlatformConfigAuditor
{
    public function __construct(
        private readonly PaymentGatewaySettings $gateways,
    ) {}

    /** @return Collection<int, ConfigAuditFinding> */
    public function run(): Collection
    {
        return collect()
            ->concat($this->payments())
            ->concat($this->currencies())
            ->concat($this->pricing())
            ->concat($this->timezones())
            ->concat($this->availability())
            ->concat($this->academicLevels())
            ->values();
    }

    /** @return list<ConfigAuditFinding> */
    private function payments(): array
    {
        $section = 'Payments';
        $out = [];

        if (! $this->gateways->payments_enabled) {
            $out[] = ConfigAuditFinding::warn($section, 'Payments are disabled platform-wide.', 'Admin → Settings → Payments → Enable payments.');
        }

        if ($this->gateways->fake_enabled && app()->environment('production')) {
            $out[] = ConfigAuditFinding::fail($section, 'The FAKE payment provider is enabled in production.', 'Admin → Settings → Payments → disable the fake provider.');
        }

        foreach (['razorpay' => 'Razorpay', 'stripe' => 'Stripe'] as $key => $label) {
            $enabled = (bool) ($this->gateways->{$key.'_enabled'} ?? false);

            if (! $enabled) {
                continue;
            }

            $credentialFields = $key === 'razorpay'
                ? ['razorpay_key_id' => false, 'razorpay_key_secret' => true]
                : ['stripe_publishable_key' => false, 'stripe_secret_key' => true];

            foreach ($credentialFields as $field => $encrypted) {
                $value = $encrypted
                    ? PaymentWebhookSignatureService::decryptSecret($this->gateways, $field)
                    : $this->gateways->{$field};

                if (blank($value)) {
                    $out[] = ConfigAuditFinding::fail($section, "{$label} is enabled but {$field} is empty.", 'Admin → Settings → Payments.');
                }
            }

            $webhookField = $key.'_webhook_secret';
            $anyScope = PaymentWebhookSignatureService::decryptSecrets($this->gateways, $webhookField);

            if ($anyScope === []) {
                $out[] = ConfigAuditFinding::fail(
                    $section,
                    "{$label} is enabled but has NO webhook secret — every webhook delivery is rejected with 401, so payments only settle via the 10-minute reconciliation sweep.",
                    "Register the webhook in the {$label} dashboard and paste its secret into Admin → Settings → Payments ({$webhookField}).",
                );

                continue;
            }

            foreach ([
                PaymentWebhookSignatureService::PURPOSE_WALLET => '/api/webhooks/wallets/recharges/'.$key,
                PaymentWebhookSignatureService::PURPOSE_BOOKING => '/api/webhooks/bookings/payments/'.$key,
                PaymentWebhookSignatureService::PURPOSE_PACKAGE => '/api/webhooks/packages/purchases/'.$key,
            ] as $purpose => $path) {
                if (PaymentWebhookSignatureService::decryptSecrets($this->gateways, $webhookField, $purpose) === []) {
                    $out[] = ConfigAuditFinding::fail($section, "{$label}: no webhook secret is valid for the {$purpose} endpoint ({$path}).", "Add a line `{$purpose}:<secret>` (or an unprefixed secret) to {$webhookField}.");
                }
            }

            $out[] = ConfigAuditFinding::ok($section, "{$label}: credentials and webhook secrets present for all three endpoints.");
        }

        if (! ($this->gateways->razorpay_enabled ?? false) && ! ($this->gateways->stripe_enabled ?? false) && $this->gateways->payments_enabled) {
            $out[] = ConfigAuditFinding::fail($section, 'Payments are enabled but no real provider (Razorpay/Stripe) is enabled.', 'Enable and configure a provider.');
        }

        return $out;
    }

    /** @return list<ConfigAuditFinding> */
    private function currencies(): array
    {
        $section = 'Countries & currencies';
        $out = [];
        $countries = Country::query()->active()->with('defaultCurrency')->orderBy('name')->get();

        if ($countries->isEmpty()) {
            return [ConfigAuditFinding::fail($section, 'No active countries — nobody can register or book.', 'Admin → Countries → activate at least one.')];
        }

        $international = collect($this->gateways->razorpay_international_currencies ?? [])->map(fn ($c) => strtoupper((string) $c));

        foreach ($countries as $country) {
            $currency = $country->defaultCurrency;

            if ($currency === null) {
                $out[] = ConfigAuditFinding::fail($section, "{$country->name} has no default currency — prices and wallets cannot be created for its students.", 'Admin → Countries → set a default currency.');

                continue;
            }

            if ($currency->status !== 'active') {
                $out[] = ConfigAuditFinding::fail($section, "{$country->name}'s default currency {$currency->code} is inactive — no payment in it can be initiated.", 'Admin → Currencies → activate it, or change the country default.');
            }

            if (($this->gateways->razorpay_enabled ?? false) && ! ($this->gateways->stripe_enabled ?? false)
                && strtoupper($currency->code) !== 'INR'
                && (! ($this->gateways->razorpay_international_enabled ?? false) || ! $international->contains(strtoupper($currency->code)))) {
                $out[] = ConfigAuditFinding::fail($section, "{$country->name} bills in {$currency->code}, but Razorpay is the only provider and international payments are not enabled for {$currency->code}.", 'Admin → Settings → Payments → Razorpay → enable International Payments and add the currency, or enable Stripe.');
            }
        }

        if ($out === []) {
            $out[] = ConfigAuditFinding::ok($section, sprintf('%d active countries, all with an active, collectable default currency.', $countries->count()));
        }

        return $out;
    }

    /** @return list<ConfigAuditFinding> */
    private function pricing(): array
    {
        $section = 'Lesson prices';
        $out = [];

        $paidTypes = BookingType::query()->active()->where('is_paid', true)->get();

        if ($paidTypes->isEmpty()) {
            return [ConfigAuditFinding::warn($section, 'No active paid booking type — nothing to price.')];
        }

        // Countries that actually have students are the ones a missing
        // price row hurts today; a country with no students yet is noise.
        $studentCountryIds = UserProfile::query()
            ->whereNotNull('country_id')
            ->whereIn('user_id', User::query()->role('student')->select('id'))
            ->distinct()
            ->pluck('country_id');
        $countries = Country::query()->active()->whereIn('id', $studentCountryIds)->orderBy('name')->get();

        // Subjects at least one approved instructor teaches.
        $approvedInstructorIds = UserProfile::query()->where('instructor_status', 'approved')->pluck('user_id');
        $taughtSubjectIds = TeacherSubject::query()->whereIn('teacher_id', $approvedInstructorIds)->whereNotNull('subject_id')->distinct()->pluck('subject_id');
        $subjects = Subject::query()->active()->whereIn('id', $taughtSubjectIds)->orderBy('name')->get();

        $rows = StudentLessonPrice::query()->active()->effectiveNow()->whereNull('instructor_id')->get();
        $missing = 0;

        foreach ($paidTypes as $type) {
            foreach ($countries as $country) {
                foreach ($subjects as $subject) {
                    $match = $rows->first(fn (StudentLessonPrice $p): bool => $p->booking_type_id === $type->id
                        && (string) $p->subject_id === (string) $subject->id
                        && (int) $p->country_id === (int) $country->id
                        && (int) $p->duration_minutes === (int) $type->duration_minutes);

                    if ($match === null) {
                        $missing++;
                        $out[] = ConfigAuditFinding::fail(
                            $section,
                            sprintf('No base price for "%s" · %s · %s · %d min — a student there sees "price is not configured".', $type->name, $subject->name, $country->name, $type->duration_minutes),
                            'Admin → Student Lesson Prices → add a row (leave Instructor and Academic level empty for a catch-all).',
                        );
                    }
                }
            }
        }

        // Rows that can never match anything.
        $typeDurations = $paidTypes->keyBy('id')->map(fn (BookingType $t): int => (int) $t->duration_minutes);

        foreach (StudentLessonPrice::query()->active()->with(['bookingType', 'academicLevel'])->get() as $row) {
            $expected = $typeDurations->get($row->booking_type_id);

            if ($expected !== null && (int) $row->duration_minutes !== $expected) {
                $out[] = ConfigAuditFinding::warn($section, sprintf('Price row %s is for %d min but its booking type "%s" lasts %d min — it can never match.', $row->id, $row->duration_minutes, $row->bookingType?->name, $expected), 'Edit the row so the duration equals the booking type duration.');
            }

            if ($row->academic_level_id !== null && ($row->academicLevel === null || $row->academicLevel->status->value !== 'active')) {
                $out[] = ConfigAuditFinding::warn($section, sprintf('Price row %s points at an inactive or deleted academic level — it can never match.', $row->id), 'Re-point the row at an active level or clear the level.');
            }
        }

        if ($missing === 0 && $countries->isNotEmpty() && $subjects->isNotEmpty()) {
            $out[] = ConfigAuditFinding::ok($section, sprintf('Every paid type has a base price for all %d subject(s) in all %d student countr%s.', $subjects->count(), $countries->count(), $countries->count() === 1 ? 'y' : 'ies'));
        }

        return $out;
    }

    /** @return list<ConfigAuditFinding> */
    private function timezones(): array
    {
        $section = 'Time zones';
        $out = [];

        $invalid = UserProfile::query()
            ->whereNotNull('timezone')
            ->get(['user_id', 'timezone'])
            ->reject(fn (UserProfile $p): bool => IanaTimezone::isValid($p->timezone));

        if ($invalid->isNotEmpty()) {
            $out[] = ConfigAuditFinding::fail($section, sprintf('%d user profile(s) carry an invalid time zone (e.g. "%s") — their slots and reminders fall back to the platform default.', $invalid->count(), $invalid->first()->timezone), 'Fix via Admin → Users, or ask the user to re-select their time zone.');
        }

        $availabilityWithoutZone = TeacherAvailability::query()->active()
            ->where(fn ($q) => $q->whereNull('timezone')->orWhere('timezone', ''))
            ->with('teacher.profile')
            ->get()
            ->filter(fn (TeacherAvailability $a): bool => ! IanaTimezone::isValid($a->teacher?->profile?->timezone));

        if ($availabilityWithoutZone->isNotEmpty()) {
            $out[] = ConfigAuditFinding::fail($section, sprintf('%d availability window(s) have no time zone and their instructor has none either — slots would be published at the wrong hour.', $availabilityWithoutZone->count()), 'Set the instructor profile time zone.');
        }

        if ($out === []) {
            $out[] = ConfigAuditFinding::ok($section, 'All stored user and availability time zones are valid IANA identifiers.');
        }

        return $out;
    }

    /** @return list<ConfigAuditFinding> */
    private function availability(): array
    {
        $section = 'Instructor availability';
        $approved = UserProfile::query()->where('instructor_status', 'approved')->pluck('user_id');
        $withWindows = TeacherAvailability::query()->active()->whereIn('teacher_id', $approved)->distinct()->pluck('teacher_id');
        $without = $approved->diff($withWindows);

        if ($without->isNotEmpty()) {
            return [ConfigAuditFinding::warn($section, sprintf('%d approved instructor(s) have no active availability and can never be booked (user ids: %s).', $without->count(), $without->take(10)->implode(', ')), 'Ask them to set availability, or expect them to be absent from search.')];
        }

        return [ConfigAuditFinding::ok($section, sprintf('All %d approved instructors have active availability.', $approved->count()))];
    }

    /** @return list<ConfigAuditFinding> */
    private function academicLevels(): array
    {
        $section = 'Academic levels';
        $out = [];
        $levels = AcademicLevel::query()->active()->get();

        // Several levels covering one grade in one country is legal, but it
        // is exactly the situation in which a price on the "wrong" level
        // used to be invisible. Surface it so admins price deliberately.
        foreach ($levels->groupBy(fn (AcademicLevel $l) => $l->country_id ?? 'global') as $countryKey => $group) {
            for ($grade = 1; $grade <= 12; $grade++) {
                $covering = $group->filter(fn (AcademicLevel $l): bool => $l->coversGrade($grade));

                if ($covering->count() > 1) {
                    $where = $countryKey === 'global' ? 'global levels' : 'country #'.$countryKey;
                    $out[] = ConfigAuditFinding::warn($section, sprintf('Grade %d is covered by %d levels among %s (%s) — price rows keyed on a level must exist for the one students actually pick, or use an all-levels row.', $grade, $covering->count(), $where, $covering->pluck('name')->implode(', ')));
                }
            }
        }

        if ($levels->isEmpty()) {
            $out[] = ConfigAuditFinding::fail($section, 'No active academic levels — the booking wizard cannot offer a grade.', 'Admin → Academics → Levels.');
        }

        if ($out === []) {
            $out[] = ConfigAuditFinding::ok($section, sprintf('%d active levels, no overlapping grade coverage.', $levels->count()));
        }

        return $out;
    }
}
