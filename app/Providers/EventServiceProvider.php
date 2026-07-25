<?php

declare(strict_types=1);

namespace App\Providers;

use App\Booking\Events\BookingCancelled;
use App\Booking\Events\BookingCompleted;
use App\Booking\Events\BookingConfirmed;
use App\Booking\Events\BookingPaymentSucceeded;
use App\Booking\Events\BookingRequested;
use App\Booking\Events\BookingRescheduled;
use App\Booking\Events\MeetingCreated;
use App\Booking\Events\MeetingUpdated;
use App\Compliance\Events\SuspiciousActivityFlagRecorded;
use App\Events\ActivityCreated;
use App\Events\Auth\LoginFailed;
use App\Events\Auth\UserApproved;
use App\Events\Auth\UserLoggedIn;
use App\Events\Auth\UserLoggedOut;
use App\Events\Auth\UserRegistered;
use App\Lessons\Events\LessonCancelled;
use App\Lessons\Events\LessonCompleted;
use App\Lessons\Events\LessonDisputed;
use App\Lessons\Events\LessonOutcomeFinalized;
use App\Lessons\Events\LessonOutcomeOverridden;
use App\Listeners\Auth\LogLoginActivity;
use App\Listeners\Auth\SendApprovalNotification;
use App\Listeners\Auth\SendRegistrationNotifications;
use App\Listeners\Auth\SendWelcomeNotification;
use App\Listeners\Booking\CancelMeetingOnBookingCancelled;
use App\Listeners\Booking\CreateMeetingOnBookingConfirmed;
use App\Listeners\Booking\RecordBookingLifecycleAudit;
use App\Listeners\Booking\SendBookingNotifications;
use App\Listeners\Booking\SendMeetingNotifications;
use App\Listeners\Booking\SyncPaymentOnCancellation;
use App\Listeners\Compliance\EvaluateExcessiveBookingCancellationsOnBookingCancelled;
use App\Listeners\Compliance\EvaluateRepeatedFailedLoginsOnLoginFailed;
use App\Listeners\Compliance\EvaluateRepeatedReferralFraudHoldsOnReferralRewardHeld;
use App\Listeners\Compliance\SendSuspiciousActivityFlagRecordedNotification;
use App\Listeners\Earnings\ClassifyLessonFinancialDisposition;
use App\Listeners\Earnings\CreateEarningOnLessonCompleted;
use App\Listeners\Earnings\ReevaluateLessonFinancialDisposition;
use App\Listeners\Earnings\ReverseEarningOnLessonCancelled;
use App\Listeners\Earnings\SyncEarningOnLessonDisputed;
use App\Listeners\LearningPlans\RecalculateLearningPlanProgressOnLessonOutcomeFinalized;
use App\Listeners\LearningPlans\RecalculateLearningPlanProgressOnLessonOutcomeOverridden;
use App\Listeners\Lesson\CreateLessonOnBookingConfirmed;
use App\Listeners\Lesson\SyncLessonOnBookingCancelled;
use App\Listeners\Lesson\SyncLessonOnBookingCompleted;
use App\Listeners\Mail\LogMailSending;
use App\Listeners\Mail\LogMailSent;
use App\Listeners\Mail\LogNotificationFailed;
use App\Listeners\Mail\LogNotificationSending;
use App\Listeners\Mail\LogNotificationSent;
use App\Listeners\Mail\LogResendEmailEvent;
use App\Listeners\NotifyAdminsOnActivity;
use App\Listeners\NotifyInstructorOnPayoutActivity;
use App\Listeners\NotifyInstructorOnProfileActivity;
use App\Listeners\Payment\GenerateInvoiceOnBookingPaymentSucceeded;
use App\Listeners\Quality\DetectInstructorCancellationQualityRiskOnBookingCancelled;
use App\Listeners\Quality\DetectInstructorNoShowQualityRiskOnLessonOutcomeFinalized;
use App\Listeners\Quality\DetectLowRatingQualityRiskOnStudentReviewPublished;
use App\Listeners\Quality\DetectSeriousReviewReportQualityRiskOnReviewReportUpheld;
use App\Listeners\Quality\ReevaluateQualityAlertOnLessonOutcomeOverridden;
use App\Listeners\Quality\ReevaluateQualityAlertOnStudentReviewArchived;
use App\Listeners\Quality\ReevaluateQualityAlertOnStudentReviewHidden;
use App\Listeners\Quality\ReevaluateQualityAlertOnStudentReviewRejected;
use App\Listeners\Quality\SendInstructorQualityAlertCreatedNotification;
use App\Listeners\Reviews\ModerateReviewOnStudentReviewEdited;
use App\Listeners\Reviews\ModerateReviewOnStudentReviewSubmitted;
use App\Listeners\Reviews\OpenReviewEligibilityOnLessonOutcomeFinalized;
use App\Listeners\Reviews\ReconcileRatingContributionOnStudentReviewArchived;
use App\Listeners\Reviews\ReconcileRatingContributionOnStudentReviewEdited;
use App\Listeners\Reviews\ReconcileRatingContributionOnStudentReviewHidden;
use App\Listeners\Reviews\ReconcileRatingContributionOnStudentReviewPublished;
use App\Listeners\Reviews\ReconcileRatingContributionOnStudentReviewRejected;
use App\Listeners\Reviews\ReconcileRatingContributionOnStudentReviewRestored;
use App\Listeners\Reviews\ReevaluateReviewEligibilityOnLessonOutcomeOverridden;
use App\Listeners\Reviews\SendReviewHiddenNotification;
use App\Listeners\Reviews\SendReviewModerationRequiredNotification;
use App\Listeners\Reviews\SendReviewPublishedNotifications;
use App\Listeners\Reviews\SendReviewRejectedNotification;
use App\Listeners\Reviews\SendReviewReportedNotification;
use App\Listeners\Reviews\SendReviewRequestedNotification;
use App\Listeners\Reviews\SendReviewSubmittedNotification;
use App\Listeners\SupportCases\SendSupportCaseNotifications;
use App\Listeners\Waitlist\FulfillWaitlistOnBookingConfirmed;
use App\Listeners\Waitlist\ProcessWaitlistOnBookingCancelled;
use App\Listeners\Waitlist\ProcessWaitlistOnBookingRescheduled;
use App\Listeners\Waitlist\ProcessWaitlistOnInstructorAvailabilityOpened;
use App\Listeners\Wallet\GenerateInvoiceOnWalletRechargeSucceeded;
use App\Listeners\Wallet\SendWalletNotifications;
use App\Quality\Events\InstructorQualityAlertCreated;
use App\Referral\Events\ReferralRewardCredited;
use App\Referral\Events\ReferralRewardHeld;
use App\Referral\Events\ReferralRewardReversed;
use App\Referral\Listeners\EvaluateReferralRewardOnLessonOutcomeFinalized;
use App\Referral\Listeners\ReevaluateReferralRewardOnLessonOutcomeOverridden;
use App\Referral\Listeners\ReverseReferralRewardOnLessonRefundCompleted;
use App\Referral\Listeners\SendReferralRewardNotifications;
use App\Reviews\Events\LessonReviewEligibilityOpened;
use App\Reviews\Events\ReviewReported;
use App\Reviews\Events\ReviewReportUpheld;
use App\Reviews\Events\StudentReviewArchived;
use App\Reviews\Events\StudentReviewEdited;
use App\Reviews\Events\StudentReviewHidden;
use App\Reviews\Events\StudentReviewModerationRequired;
use App\Reviews\Events\StudentReviewPublished;
use App\Reviews\Events\StudentReviewRejected;
use App\Reviews\Events\StudentReviewRestored;
use App\Reviews\Events\StudentReviewSubmitted;
use App\SupportCases\Events\SupportCaseAssigned;
use App\SupportCases\Events\SupportCaseCreated;
use App\SupportCases\Events\SupportCaseReplyAdded;
use App\SupportCases\Events\SupportCaseStatusChanged;
use App\Waitlist\Events\InstructorAvailabilityOpened;
use App\Wallet\Events\LessonRefundCompleted;
use App\Wallet\Events\WalletRechargeSucceeded;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Resend\Laravel\Events\EmailBounced;
use Resend\Laravel\Events\EmailComplained;
use Resend\Laravel\Events\EmailDelivered;
use Resend\Laravel\Events\EmailDeliveryDelayed;
use Resend\Laravel\Events\EmailFailed;
use Resend\Laravel\Events\EmailSent;
use Resend\Laravel\Events\EmailSuppressed;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        ActivityCreated::class => [
            NotifyAdminsOnActivity::class,
            NotifyInstructorOnProfileActivity::class,
            NotifyInstructorOnPayoutActivity::class,
        ],
        UserRegistered::class => [
            SendRegistrationNotifications::class,
        ],
        // Welcome email fires only after the user clicks the verification link
        Verified::class => [
            SendWelcomeNotification::class,
        ],
        // Fired from EditUser when admin changes status from INACTIVE → ACTIVE
        UserApproved::class => [
            SendApprovalNotification::class,
        ],
        UserLoggedIn::class => [
            [LogLoginActivity::class, 'handleUserLoggedIn'],
        ],
        UserLoggedOut::class => [
            [LogLoginActivity::class, 'handleUserLoggedOut'],
        ],
        LoginFailed::class => [
            [LogLoginActivity::class, 'handleLoginFailed'],
            EvaluateRepeatedFailedLoginsOnLoginFailed::class,
        ],
        NotificationSending::class => [
            LogNotificationSending::class,
        ],
        NotificationSent::class => [
            LogNotificationSent::class,
        ],
        NotificationFailed::class => [
            LogNotificationFailed::class,
        ],
        MessageSending::class => [
            LogMailSending::class,
        ],
        MessageSent::class => [
            LogMailSent::class,
        ],
        EmailSent::class => [
            [LogResendEmailEvent::class, 'handleEmailSent'],
        ],
        EmailDelivered::class => [
            [LogResendEmailEvent::class, 'handleEmailDelivered'],
        ],
        EmailFailed::class => [
            [LogResendEmailEvent::class, 'handleEmailFailed'],
        ],
        EmailBounced::class => [
            [LogResendEmailEvent::class, 'handleEmailBounced'],
        ],
        EmailComplained::class => [
            [LogResendEmailEvent::class, 'handleEmailComplained'],
        ],
        EmailDeliveryDelayed::class => [
            [LogResendEmailEvent::class, 'handleEmailDelayed'],
        ],
        EmailSuppressed::class => [
            [LogResendEmailEvent::class, 'handleEmailSuppressed'],
        ],
        BookingRequested::class => [
            [SendBookingNotifications::class, 'handleRequested'],
            [RecordBookingLifecycleAudit::class, 'handleRequested'],
        ],
        BookingConfirmed::class => [
            [SendBookingNotifications::class, 'handleConfirmed'],
            [RecordBookingLifecycleAudit::class, 'handleConfirmed'],
            CreateMeetingOnBookingConfirmed::class,
            CreateLessonOnBookingConfirmed::class,
            FulfillWaitlistOnBookingConfirmed::class,
        ],
        BookingCancelled::class => [
            [SendBookingNotifications::class, 'handleCancelled'],
            [RecordBookingLifecycleAudit::class, 'handleCancelled'],
            SyncPaymentOnCancellation::class,
            SyncLessonOnBookingCancelled::class,
            CancelMeetingOnBookingCancelled::class,
            DetectInstructorCancellationQualityRiskOnBookingCancelled::class,
            ProcessWaitlistOnBookingCancelled::class,
            EvaluateExcessiveBookingCancellationsOnBookingCancelled::class,
        ],
        BookingRescheduled::class => [
            [SendBookingNotifications::class, 'handleRescheduled'],
            [RecordBookingLifecycleAudit::class, 'handleRescheduled'],
            ProcessWaitlistOnBookingRescheduled::class,
        ],
        BookingCompleted::class => [
            [SendBookingNotifications::class, 'handleCompleted'],
            [RecordBookingLifecycleAudit::class, 'handleCompleted'],
            SyncLessonOnBookingCompleted::class,
        ],
        BookingPaymentSucceeded::class => [
            [SendBookingNotifications::class, 'handlePaymentSucceeded'],
            GenerateInvoiceOnBookingPaymentSucceeded::class,
        ],
        WalletRechargeSucceeded::class => [
            [SendWalletNotifications::class, 'handleRechargeSucceeded'],
            GenerateInvoiceOnWalletRechargeSucceeded::class,
        ],
        InstructorAvailabilityOpened::class => [
            ProcessWaitlistOnInstructorAvailabilityOpened::class,
        ],
        MeetingCreated::class => [
            [SendMeetingNotifications::class, 'handleCreated'],
        ],
        MeetingUpdated::class => [
            [SendMeetingNotifications::class, 'handleUpdated'],
        ],
        LessonCompleted::class => [
            CreateEarningOnLessonCompleted::class,
        ],
        LessonDisputed::class => [
            SyncEarningOnLessonDisputed::class,
        ],
        LessonCancelled::class => [
            ReverseEarningOnLessonCancelled::class,
        ],
        // Phase 17E — the financial-decision bridge: classification and
        // holds only, gated by financial_disposition_enabled; earning
        // creation stays exclusively with CreateEarningOnLessonCompleted.
        LessonOutcomeFinalized::class => [
            ClassifyLessonFinancialDisposition::class,
            OpenReviewEligibilityOnLessonOutcomeFinalized::class,
            DetectInstructorNoShowQualityRiskOnLessonOutcomeFinalized::class,
            // Phase 19D — the only automatic referral-reward trigger.
            EvaluateReferralRewardOnLessonOutcomeFinalized::class,
            RecalculateLearningPlanProgressOnLessonOutcomeFinalized::class,
        ],
        LessonOutcomeOverridden::class => [
            ReevaluateLessonFinancialDisposition::class,
            ReevaluateReviewEligibilityOnLessonOutcomeOverridden::class,
            ReevaluateQualityAlertOnLessonOutcomeOverridden::class,
            ReevaluateReferralRewardOnLessonOutcomeOverridden::class,
            RecalculateLearningPlanProgressOnLessonOutcomeOverridden::class,
        ],
        // Phase 19D — a refunded lesson invalidates its referral reward.
        LessonRefundCompleted::class => [
            ReverseReferralRewardOnLessonRefundCompleted::class,
        ],
        // Phase 19D — referrer-facing reward notifications (idempotent).
        ReferralRewardCredited::class => [
            [SendReferralRewardNotifications::class, 'handleCredited'],
        ],
        ReferralRewardHeld::class => [
            [SendReferralRewardNotifications::class, 'handleHeld'],
            EvaluateRepeatedReferralFraudHoldsOnReferralRewardHeld::class,
        ],
        ReferralRewardReversed::class => [
            [SendReferralRewardNotifications::class, 'handleReversed'],
        ],
        // Phase 17S — the eligible student is notified a completed
        // lesson is ready for review; no recurring reminder is scheduled.
        LessonReviewEligibilityOpened::class => [
            SendReviewRequestedNotification::class,
        ],
        // Phase 17J — automatic moderation of a newly submitted review.
        // Phase 17S adds the student's submission-received confirmation.
        StudentReviewSubmitted::class => [
            ModerateReviewOnStudentReviewSubmitted::class,
            SendReviewSubmittedNotification::class,
        ],
        // Phase 17S — a review reached a final state that genuinely
        // needs a human (Flagged, or Submitted with auto-publish
        // declined). Dispatched only from the authoritative decision
        // points themselves — see StudentReviewModerationRequired's
        // docblock. One notification per review version, never a
        // separate duplicate for "flagged" vs "needs moderation".
        StudentReviewModerationRequired::class => [
            SendReviewModerationRequiredNotification::class,
        ],
        // Phase 17R — an edited review re-enters the SAME moderation
        // pipeline (the action already moved it to its re-moderation
        // status), and the aggregate reconciler converges the rating
        // contribution. No notification/response/scoring listener here.
        StudentReviewEdited::class => [
            ModerateReviewOnStudentReviewEdited::class,
            ReconcileRatingContributionOnStudentReviewEdited::class,
        ],
        // Phase 17K — instructor rating aggregate reconciliation. All five
        // listeners delegate to the same idempotent reconcile() entry
        // point; no aggregate mutation happens inside moderation services.
        StudentReviewPublished::class => [
            ReconcileRatingContributionOnStudentReviewPublished::class,
            DetectLowRatingQualityRiskOnStudentReviewPublished::class,
            SendReviewPublishedNotifications::class,
        ],
        StudentReviewHidden::class => [
            ReconcileRatingContributionOnStudentReviewHidden::class,
            ReevaluateQualityAlertOnStudentReviewHidden::class,
            SendReviewHiddenNotification::class,
        ],
        StudentReviewRestored::class => [
            ReconcileRatingContributionOnStudentReviewRestored::class,
        ],
        StudentReviewRejected::class => [
            ReconcileRatingContributionOnStudentReviewRejected::class,
            ReevaluateQualityAlertOnStudentReviewRejected::class,
            SendReviewRejectedNotification::class,
        ],
        StudentReviewArchived::class => [
            ReconcileRatingContributionOnStudentReviewArchived::class,
            ReevaluateQualityAlertOnStudentReviewArchived::class,
        ],
        // Phase 17N — instructor quality-alert detection from an upheld
        // serious review report. Fingerprint keys on the review, not the
        // report, so multiple reports against the same review collapse
        // to one alert.
        ReviewReportUpheld::class => [
            DetectSeriousReviewReportQualityRiskOnReviewReportUpheld::class,
        ],
        // Phase 17S — report-authorized administrators are notified of
        // every new report. Dismissed/duplicate resolution events are
        // deliberately not wired to any notification listener here.
        ReviewReported::class => [
            SendReviewReportedNotification::class,
        ],
        // Phase 17S — quality-alert-authorized administrators are
        // notified of every new alert, regardless of type — one generic
        // notification, never a per-type template. The instructor the
        // alert concerns is never notified. Resolved/dismissed events
        // are deliberately not wired to any notification listener here.
        InstructorQualityAlertCreated::class => [
            SendInstructorQualityAlertCreatedNotification::class,
        ],
        SuspiciousActivityFlagRecorded::class => [
            SendSuspiciousActivityFlagRecordedNotification::class,
        ],
        // Phase 31 — GAP-016 support/dispute case management. Admin
        // awareness (new critical case, escalation, user replied,
        // reopened) flows separately through the Activity Log pipeline
        // (AuditTrailService → ActivityCreated → NotifyAdminsOnActivity).
        SupportCaseCreated::class => [
            [SendSupportCaseNotifications::class, 'handleCreated'],
        ],
        SupportCaseAssigned::class => [
            [SendSupportCaseNotifications::class, 'handleAssigned'],
        ],
        SupportCaseReplyAdded::class => [
            [SendSupportCaseNotifications::class, 'handleReplyAdded'],
        ],
        SupportCaseStatusChanged::class => [
            [SendSupportCaseNotifications::class, 'handleStatusChanged'],
        ],
    ];

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }

    public function boot(): void
    {
        //
    }
}
