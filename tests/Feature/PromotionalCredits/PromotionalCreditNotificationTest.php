<?php

declare(strict_types=1);

namespace Tests\Feature\PromotionalCredits;

use App\Listeners\Wallet\SendWalletNotifications;
use App\Notifications\Wallet\PromotionalCreditIssuedNotification;
use App\PromotionalCredits\Events\PromotionalCreditIssued;
use App\PromotionalCredits\Services\PromotionalCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\PromotionalCredits\Concerns\CreatesPromotionalCreditFixtures;
use Tests\TestCase;

/** SRS §16.33 "Promotional credit received" — queued, after-commit, idempotent. */
class PromotionalCreditNotificationTest extends TestCase
{
    use CreatesPromotionalCreditFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensurePromotionalCreditRoles();
    }

    public function test_issuance_dispatches_an_after_commit_event(): void
    {
        Event::fake([PromotionalCreditIssued::class]);

        $admin = $this->fullAdmin();
        $student = $this->student();

        app(PromotionalCreditService::class)->issueManualCredit($student, $admin, 15000, 'INR', 'Reason.', 'promo_credit:'.Str::uuid());

        Event::assertDispatched(PromotionalCreditIssued::class);
    }

    public function test_the_student_receives_a_notification_end_to_end(): void
    {
        Notification::fake();

        $admin = $this->fullAdmin();
        $student = $this->student();

        app(PromotionalCreditService::class)->issueManualCredit($student, $admin, 15000, 'INR', 'Reason.', 'promo_credit:'.Str::uuid());

        Notification::assertSentTo($student, PromotionalCreditIssuedNotification::class, 1);
    }

    public function test_a_redelivered_event_never_double_sends(): void
    {
        Notification::fake();

        $admin = $this->fullAdmin();
        $student = $this->student();
        $issuance = app(PromotionalCreditService::class)->issueManualCredit($student, $admin, 15000, 'INR', 'Reason.', 'promo_credit:'.Str::uuid());

        $listener = app(SendWalletNotifications::class);
        $listener->handlePromotionalCreditIssued(new PromotionalCreditIssued($issuance->id, $student->id));
        $listener->handlePromotionalCreditIssued(new PromotionalCreditIssued($issuance->id, $student->id)); // simulated redelivery

        Notification::assertSentToTimes($student, PromotionalCreditIssuedNotification::class, 1);
    }
}
