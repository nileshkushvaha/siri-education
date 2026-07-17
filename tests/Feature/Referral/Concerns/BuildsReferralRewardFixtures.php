<?php

declare(strict_types=1);

namespace Tests\Feature\Referral\Concerns;

use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Enums\StudentStatus;
use App\Lessons\Enums\LessonOutcome;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\BookingType;
use App\Models\Currency;
use App\Models\Lesson;
use App\Models\ReferralAttribution;
use App\Models\ReferralCampaign;
use App\Models\ReferralCode;
use App\Models\User;
use App\Settings\FeatureSettings;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

/**
 * Builds the full authoritative chain a referral reward depends on:
 * attributed students, a paid booking type, a captured payment, and a
 * finalized-Completed lesson — through the same domain factories every
 * other financial test uses, never through shortcut tables.
 */
trait BuildsReferralRewardFixtures
{
    protected BookingType $paidType;

    protected function setUpReferralWorld(): void
    {
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);
        Currency::query()->firstOrCreate(['code' => 'USD'], [
            'name' => 'US Dollar', 'symbol' => '$', 'numeric_code' => '840',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 2,
        ]);

        $features = app(FeatureSettings::class);
        $features->referral_enabled = true;
        $features->save();

        $this->paidType = BookingType::factory()->paid()->create();
    }

    protected function activeStudent(): User
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        $student->profile?->update(['student_status' => StudentStatus::Active]);

        return $student;
    }

    /** @return array{0: User, 1: User, 2: ReferralAttribution} */
    protected function attributedPair(): array
    {
        $referrer = $this->activeStudent();
        $referred = $this->activeStudent();

        $code = ReferralCode::factory()->create(['user_id' => $referrer->id]);

        $attribution = ReferralAttribution::factory()->create([
            'referrer_id' => $referrer->id,
            'referred_student_id' => $referred->id,
            'referral_code_id' => $code->id,
        ]);

        return [$referrer, $referred, $attribution];
    }

    protected function completedPaidLesson(
        User $referred,
        int $amountMinor = 50000,
        string $currencyCode = 'INR',
        ?Carbon $finalizedAt = null,
        ?BookingType $type = null,
    ): Lesson {
        $finalizedAt ??= now();

        $booking = Booking::factory()->confirmed()->create([
            'booking_type_id' => ($type ?? $this->paidType)->id,
            'student_id' => $referred->id,
            'status' => BookingStatus::Completed,
            'payment_status' => BookingPaymentStatus::Paid,
        ]);

        BookingPayment::query()->create([
            'booking_id' => $booking->id,
            'user_id' => $referred->id,
            'provider' => 'fake',
            'amount_minor' => $amountMinor,
            'currency_code' => $currencyCode,
            'status' => 'captured',
            'idempotency_key' => 'referral-test-'.uniqid(),
            'paid_at' => $finalizedAt,
        ]);

        $lesson = Lesson::factory()->completed()->create([
            'booking_id' => $booking->id,
            'student_id' => $referred->id,
        ]);

        $lesson->forceFill([
            'outcome' => LessonOutcome::Completed,
            'outcome_finalized_at' => $finalizedAt,
        ])->saveQuietly();

        return $lesson->refresh();
    }

    protected function activeCampaign(array $overrides = []): ReferralCampaign
    {
        return ReferralCampaign::factory()->active()->create($overrides);
    }
}
