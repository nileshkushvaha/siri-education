<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\DTOs\NotificationPayload;
use App\Models\Activity;

/**
 * Notification Policy / Mapper.
 *
 * Given an Activity, decides WHETHER to notify and returns a fully-built
 * NotificationPayload — or null if the activity should be silently ignored.
 *
 * This is the single place where the notification policy lives.
 * No other class needs to know which activities are "important".
 *
 * Adding a new notifiable event = adding one case to the match below.
 */
final class NotificationMapper
{
    public function __construct(
        private readonly ActivityUrlResolver $urlResolver,
    ) {}

    public function map(Activity $activity): ?NotificationPayload
    {
        $config = $this->getConfig($activity);

        if ($config === null) {
            return null;
        }

        return new NotificationPayload(
            activityId: $activity->id,
            title: $config['title'],
            body: $this->buildBody($activity, $config),
            icon: $config['icon'],
            color: $config['color'],
            severity: $config['color'],
            category: $activity->log_name ?? 'general',
            priority: $config['priority'],
            url: $this->urlResolver->resolve($activity),
        );
    }

    // ── Private ───────────────────────────────────────────────────────────

    /** Returns the static config for a notifiable activity, or null to silence it. */
    private function getConfig(Activity $activity): ?array
    {
        $log = $activity->log_name;
        $event = $activity->event;

        return match (true) {

            // ── Users ─────────────────────────────────────────────────────
            $log === 'users' && $event === 'created' => [
                'title' => 'New User Created',
                'actor_label' => 'Created by',
                'icon' => 'heroicon-o-user-plus',
                'color' => 'success',
                'priority' => 2,
            ],

            $log === 'users' && $event === 'roles_updated' => [
                'title' => 'User Roles Changed',
                'actor_label' => 'Changed by',
                'icon' => 'heroicon-o-shield-check',
                'color' => 'warning',
                'priority' => 2,
            ],

            $log === 'users' && $event === 'account_approved' => [
                'title' => 'User Account Approved',
                'actor_label' => 'Approved by',
                'icon' => 'heroicon-o-check-badge',
                'color' => 'success',
                'priority' => 2,
            ],

            $log === 'users' && $event === 'password_change_required' => [
                'title' => 'Password Change Required',
                'actor_label' => 'Set by',
                'icon' => 'heroicon-o-key',
                'color' => 'info',
                'priority' => 1,
            ],

            // ── Roles ─────────────────────────────────────────────────────
            $log === 'roles' && $event === 'created' => [
                'title' => 'Role Created',
                'actor_label' => 'Created by',
                'icon' => 'heroicon-o-identification',
                'color' => 'success',
                'priority' => 2,
            ],

            $log === 'roles' && $event === 'updated' => [
                'title' => 'Role Updated',
                'actor_label' => 'Updated by',
                'icon' => 'heroicon-o-pencil-square',
                'color' => 'warning',
                'priority' => 2,
            ],

            $log === 'roles' && $event === 'deleted' => [
                'title' => 'Role Deleted',
                'actor_label' => 'Deleted by',
                'icon' => 'heroicon-o-trash',
                'color' => 'danger',
                'priority' => 3,
            ],

            // ── Security settings ─────────────────────────────────────────
            $log === 'security' && $event === 'settings_updated' => [
                'title' => 'Security Settings Changed',
                'actor_label' => 'Changed by',
                'icon' => 'heroicon-o-cog-6-tooth',
                'color' => 'warning',
                'priority' => 3,
            ],

            // ── Auth — account lock lifecycle ─────────────────────────────
            $log === 'auth' && $event === 'account_locked' => [
                'title' => 'Account Locked',
                'actor_label' => 'Locked account of',
                'icon' => 'heroicon-o-lock-closed',
                'color' => 'warning',
                'priority' => 2,
            ],

            $log === 'auth' && $event === 'manual_lock' => [
                'title' => 'Account Manually Locked',
                'actor_label' => 'Locked by',
                'icon' => 'heroicon-o-lock-closed',
                'color' => 'danger',
                'priority' => 3,
            ],

            $log === 'auth' && $event === 'manual_unlock' => [
                'title' => 'Account Unlocked',
                'actor_label' => 'Unlocked by',
                'icon' => 'heroicon-o-lock-open',
                'color' => 'info',
                'priority' => 1,
            ],

            $log === 'auth' && $event === 'self_service_unlock' => [
                'title' => 'Account Self-Service Unlocked',
                'actor_label' => 'Self-unlocked by',
                'icon' => 'heroicon-o-lock-open',
                'color' => 'info',
                'priority' => 1,
            ],

            // Registration pending approval
            $log === 'auth' && $event === 'registration_pending_approval' => [
                'title' => 'New Registration Awaiting Approval',
                'actor_label' => 'Registered by',
                'icon' => 'heroicon-o-user-group',
                'color' => 'info',
                'priority' => 2,
            ],

            // ── CMS ───────────────────────────────────────────────────────
            $log === 'cms' && $event === 'auto_published' => [
                'title' => 'Content Auto-Published',
                'actor_label' => null,
                'icon' => 'heroicon-o-document-check',
                'color' => 'success',
                'priority' => 1,
            ],

            // ── Contact ───────────────────────────────────────────────────
            $log === 'contact' && $event === 'contact_form_submitted' => [
                'title' => 'New Contact Form Submission',
                'actor_label' => null,
                'icon' => 'heroicon-o-envelope',
                'color' => 'info',
                'priority' => 2,
            ],

            // ── Referral rewards (Phase 19D) — failure/review states only ──
            $log === 'referral_rewards' && $event === 'reward_credit_failed' => [
                'title' => 'Referral Reward Credit Failed',
                'actor_label' => null,
                'icon' => 'heroicon-o-exclamation-triangle',
                'color' => 'danger',
                'priority' => 1,
            ],

            $log === 'referral_rewards' && $event === 'reward_held' => [
                'title' => 'Referral Reward Held for Review',
                'actor_label' => null,
                'icon' => 'heroicon-o-hand-raised',
                'color' => 'warning',
                'priority' => 2,
            ],

            $log === 'referral_rewards' && $event === 'reward_reversal_required' => [
                'title' => 'Referral Reward Needs Manual Reversal',
                'actor_label' => null,
                'icon' => 'heroicon-o-arrow-uturn-left',
                'color' => 'danger',
                'priority' => 1,
            ],

            // ── Public forms (Callback / Feedback / Support / General Inquiry) ──
            $log === 'forms' && $event === 'callback_requested' => [
                'title' => 'New Callback Request',
                'actor_label' => null,
                'icon' => 'heroicon-o-phone',
                'color' => 'info',
                'priority' => 2,
            ],

            $log === 'forms' && $event === 'feedback_submitted' => [
                'title' => 'New Feedback Submitted',
                'actor_label' => null,
                'icon' => 'heroicon-o-chat-bubble-left-right',
                'color' => 'info',
                'priority' => 1,
            ],

            $log === 'forms' && $event === 'support_requested' => [
                'title' => 'New Support Request',
                'actor_label' => null,
                'icon' => 'heroicon-o-lifebuoy',
                'color' => 'warning',
                'priority' => 2,
            ],

            $log === 'forms' && $event === 'inquiry_submitted' => [
                'title' => 'New General Inquiry',
                'actor_label' => null,
                'icon' => 'heroicon-o-envelope',
                'color' => 'info',
                'priority' => 1,
            ],

            // ── Newsletter ────────────────────────────────────────────────
            $log === 'newsletter' && $event === 'newsletter_subscribed' => [
                'title' => 'New Newsletter Subscriber',
                'actor_label' => null,
                'icon' => 'heroicon-o-envelope-open',
                'color' => 'success',
                'priority' => 1,
            ],

            // ── FAQs ──────────────────────────────────────────────────────
            $log === 'faqs' && $event === 'published' => [
                'title' => 'FAQ Published',
                'actor_label' => 'Published by',
                'icon' => 'heroicon-o-question-mark-circle',
                'color' => 'success',
                'priority' => 1,
            ],

            $log === 'faqs' && $event === 'deleted' => [
                'title' => 'FAQ Deleted',
                'actor_label' => 'Deleted by',
                'icon' => 'heroicon-o-trash',
                'color' => 'danger',
                'priority' => 1,
            ],

            // ── Instructor profile lifecycle ───────────────────────────────
            $log === 'instructor' && $event === 'profile_approved' => [
                'title' => 'Instructor Profile Approved',
                'actor_label' => 'Approved by',
                'icon' => 'heroicon-o-check-badge',
                'color' => 'success',
                'priority' => 2,
            ],

            $log === 'instructor' && $event === 'profile_rejected' => [
                'title' => 'Instructor Profile Rejected',
                'actor_label' => 'Rejected by',
                'icon' => 'heroicon-o-x-circle',
                'color' => 'danger',
                'priority' => 2,
            ],

            $log === 'instructor' && $event === 'profile_published' => [
                'title' => 'Instructor Profile Published',
                'actor_label' => 'Published by',
                'icon' => 'heroicon-o-globe-alt',
                'color' => 'success',
                'priority' => 1,
            ],

            // ── Booking lifecycle (semantic events from RecordBookingLifecycleAudit;
            //     the model's generic created/updated rows stay silent) ─────
            $log === 'bookings' && $event === 'booking_requested' => [
                'title' => 'New Booking',
                'actor_label' => null,
                'icon' => 'heroicon-o-calendar-days',
                'color' => 'success',
                'priority' => 2,
            ],

            $log === 'bookings' && $event === 'booking_confirmed' => [
                'title' => 'Booking Confirmed',
                'actor_label' => null,
                'icon' => 'heroicon-o-check-badge',
                'color' => 'info',
                'priority' => 1,
            ],

            $log === 'bookings' && $event === 'booking_cancelled' => [
                'title' => 'Booking Cancelled',
                'actor_label' => null,
                'icon' => 'heroicon-o-x-circle',
                'color' => 'danger',
                'priority' => 2,
            ],

            $log === 'bookings' && $event === 'booking_rescheduled' => [
                'title' => 'Booking Rescheduled',
                'actor_label' => null,
                'icon' => 'heroicon-o-arrow-path',
                'color' => 'warning',
                'priority' => 1,
            ],

            $log === 'bookings' && $event === 'booking_completed' => [
                'title' => 'Booking Completed',
                'actor_label' => null,
                'icon' => 'heroicon-o-check-circle',
                'color' => 'success',
                'priority' => 1,
            ],

            // ── Meetings (Phase 12) — audit entries written by
            //     BookingMeetingService; failure reasons are pre-sanitized ──
            $log === 'bookings' && $event === 'meeting_creation_failed' => [
                'title' => 'Meeting Creation Failed',
                'actor_label' => null,
                'icon' => 'heroicon-o-video-camera-slash',
                'color' => 'danger',
                'priority' => 3,
            ],

            // A cancellation that failed provider-side leaves a live,
            // joinable meeting behind for a booking that no longer
            // stands — someone must clean up the orphaned event manually.
            $log === 'bookings' && $event === 'meeting_cancellation_failed' => [
                'title' => 'Meeting Cancellation Failed',
                'actor_label' => null,
                'icon' => 'heroicon-o-video-camera-slash',
                'color' => 'danger',
                'priority' => 3,
            ],

            // ── Lessons (Phase 13) — only the outcomes needing admin
            //     attention map here. lesson_completed stays silent: the
            //     booking sync already raises "Booking Completed" for the
            //     same event, and mapping both would double-notify. ──────
            $log === 'lessons' && $event === 'lesson_no_show' => [
                'title' => 'Lesson No-Show',
                'actor_label' => null,
                'icon' => 'heroicon-o-eye-slash',
                'color' => 'warning',
                'priority' => 2,
            ],

            $log === 'lessons' && $event === 'lesson_disputed' => [
                'title' => 'Lesson Disputed',
                'actor_label' => 'Disputed by',
                'icon' => 'heroicon-o-exclamation-triangle',
                'color' => 'danger',
                'priority' => 3,
            ],

            // ── Payments — Option B late-terminal capture that could not be
            //     auto-credited; needs admin/support follow-up ──────────────
            $log === 'payments' && $event === 'payment_late_terminal_manual_resolution' => [
                'title' => 'Payment Needs Manual Resolution',
                'actor_label' => null,
                'icon' => 'heroicon-o-exclamation-triangle',
                'color' => 'danger',
                'priority' => 3,
            ],

            // ── Instructor payouts (Phase 15) — only the two entry points
            //     needing staff action map here; the audit descriptions are
            //     pre-sanitized (reference + masked label only, no bank
            //     details ever) ─────────────────────────────────────────────
            $log === 'instructor_payouts' && $event === 'payout_method_submitted' => [
                'title' => 'Payout Method Awaiting Verification',
                'actor_label' => 'Submitted by',
                'icon' => 'heroicon-o-building-library',
                'color' => 'warning',
                'priority' => 2,
            ],

            $log === 'instructor_payouts' && $event === 'withdrawal_requested' => [
                'title' => 'New Withdrawal Request',
                'actor_label' => 'Requested by',
                'icon' => 'heroicon-o-banknotes',
                'color' => 'warning',
                'priority' => 2,
            ],

            // ── Instructor compensation (Phase 14.2) — a completed lesson
            //     with no applicable agreement needs an admin to configure
            //     compensation, then the lesson can be retried ─────────────
            $log === 'instructor_compensation' && $event === 'earning_blocked_no_agreement' => [
                'title' => 'Lesson Earning Blocked — No Compensation Agreement',
                'actor_label' => null,
                'icon' => 'heroicon-o-exclamation-triangle',
                'color' => 'danger',
                'priority' => 3,
            ],

            // ── Payout execution (Phase 16A) — finance-facing outcomes.
            //     "processing_started" and successful payment stay
            //     silent here (routine; the instructor is notified
            //     separately) — only what needs finance attention maps. ──
            $log === 'instructor_payouts' && $event === 'withdrawal_failed' => [
                'title' => 'Withdrawal Payout Failed',
                'actor_label' => null,
                'icon' => 'heroicon-o-x-circle',
                'color' => 'danger',
                'priority' => 3,
            ],

            $log === 'instructor_payouts' && $event === 'withdrawal_reversed' => [
                'title' => 'Withdrawal Payout Reversed',
                'actor_label' => null,
                'icon' => 'heroicon-o-arrow-uturn-left',
                'color' => 'danger',
                'priority' => 3,
            ],

            $log === 'instructor_payout_execution' && $event === 'payout_attempt_queued' => [
                'title' => 'Payout Queued for Execution',
                'actor_label' => 'Queued by',
                'icon' => 'heroicon-o-paper-airplane',
                'color' => 'info',
                'priority' => 1,
            ],

            $log === 'instructor_payout_execution' && $event === 'payout_reconciliation_issue_detected' => [
                'title' => 'Payout Reconciliation Issue',
                'actor_label' => null,
                'icon' => 'heroicon-o-exclamation-triangle',
                'color' => 'danger',
                'priority' => 3,
            ],

            $log === 'instructor_payout_execution' && $event === 'payout_retry_exhausted' => [
                'title' => 'Payout Retry Budget Exhausted',
                'actor_label' => null,
                'icon' => 'heroicon-o-clock',
                'color' => 'danger',
                'priority' => 2,
            ],

            // ── Everything else: silence ──────────────────────────────────
            default => null,
        };
    }

    private function buildBody(Activity $activity, array $config): string
    {
        $parts = [];

        // Subject name (the thing acted upon)
        $subject = $activity->subject;
        if ($subject && method_exists($subject, 'getFilamentName')) {
            $parts[] = $subject->getFilamentName();
        } elseif ($subject && isset($subject->name)) {
            $parts[] = $subject->name;
        } elseif ($subject && isset($subject->email)) {
            $parts[] = $subject->email;
        }

        // Actor line — use model helper so guest/system actors render correctly
        if ($config['actor_label'] !== null) {
            $parts[] = $config['actor_label'].': '.$activity->actorName();
        }

        // Activity description as fallback when nothing else
        if (empty($parts) && $activity->description) {
            $parts[] = $activity->description;
        }

        return implode(' · ', array_filter($parts));
    }
}
