<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('booking.demo_duration_minutes', 30);
        $this->migrator->add('booking.reservation_expiry_minutes', 30);
        $this->migrator->add('booking.minimum_booking_notice_minutes', 120);
        $this->migrator->add('booking.maximum_advance_booking_days', 90);
        $this->migrator->add('booking.cancellation_window_hours', 24);
        $this->migrator->add('booking.reschedule_limit', 2);
        $this->migrator->add('booking.no_show_grace_minutes', 10);
        $this->migrator->add('booking.auto_completion_delay_minutes', 1440);

        $this->migrator->add('wallet.enabled', false);
        $this->migrator->add('wallet.minimum_recharge_amount', 100.0);
        $this->migrator->add('wallet.maximum_recharge_amount', 50000.0);
        $this->migrator->add('wallet.low_balance_threshold', 500.0);
        $this->migrator->add('wallet.recurring_deduction_hours_before_lesson', 24);

        $this->migrator->add('meeting.active_provider', 'manual');
        $this->migrator->add('meeting.platform_meeting_account', null);
        $this->migrator->add('meeting.meeting_link_visible_before_minutes', 15);
        $this->migrator->add('meeting.meeting_link_visible_after_minutes', 60);
        $this->migrator->add('meeting.recording_enabled', false);
        $this->migrator->add('meeting.recording_retention_days', 30);

        $this->migrator->add('instructor.approval_required', true);
        $this->migrator->add('instructor.profile_publish_requires_approval', true);
        $this->migrator->add('instructor.featured_instructor_limit', 8);
        $this->migrator->add('instructor.availability_required_for_public_profile', false);

        $this->migrator->add('referral.enabled', false);
        $this->migrator->add('referral.reward_type', 'wallet_credit');
        $this->migrator->add('referral.referrer_reward_amount', 0.0);
        $this->migrator->add('referral.referee_reward_amount', 0.0);
        $this->migrator->add('referral.reward_unlock_days', 0);

        $this->migrator->add('localization.default_country', 'IN');
        $this->migrator->add('localization.fallback_currency', 'INR');
        $this->migrator->add('localization.fallback_language', 'en');
        $this->migrator->add('localization.fallback_timezone', 'Asia/Kolkata');
        $this->migrator->add('localization.country_detection_enabled', false);
        $this->migrator->add('localization.allow_user_locale_switching', false);

        $this->migrator->add('features.demo_lessons_enabled', true);
        $this->migrator->add('features.wallet_enabled', false);
        $this->migrator->add('features.referral_enabled', false);
        $this->migrator->add('features.waitlist_enabled', false);
        $this->migrator->add('features.homework_enabled', true);
        $this->migrator->add('features.reviews_enabled', false);
        $this->migrator->add('features.recording_enabled', false);
    }
};
