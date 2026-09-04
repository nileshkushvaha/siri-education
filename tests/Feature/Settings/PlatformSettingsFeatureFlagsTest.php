<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Filament\Pages\Settings\PlatformFoundationSettingsPage;
use App\Models\Activity;
use App\Models\User;
use App\Settings\BookingSettings;
use App\Settings\FeatureSettings;
use App\Settings\InstructorSettings;
use App\Settings\LocalizationSettings;
use App\Settings\MeetingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PlatformSettingsFeatureFlagsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--path' => 'database/settings']);
    }

    public function test_platform_foundation_settings_load_defaults(): void
    {
        $booking = app(BookingSettings::class);
        $meeting = app(MeetingSettings::class);
        $instructor = app(InstructorSettings::class);
        $localization = app(LocalizationSettings::class);
        $features = app(FeatureSettings::class);

        $this->assertSame(30, $booking->demo_duration_minutes);
        $this->assertSame(120, $booking->minimum_booking_notice_minutes);
        $this->assertSame(90, $booking->maximum_advance_booking_days);
        // Wallet rules (recharge min/max/step, low-balance alert) are
        // per-currency columns on `currencies`, edited on Settings → Wallet;
        // no wallet settings class exists any more.
        $this->assertSame('manual', $meeting->default_provider);
        $this->assertTrue($instructor->approval_required);
        $this->assertSame('IN', $localization->default_country);
        $this->assertTrue($features->demo_lessons_enabled);
        $this->assertFalse(property_exists($features, 'country_academic_booking_enabled'));
        $this->assertFalse($features->wallet_enabled);
        $this->assertFalse($features->referral_enabled);
        $this->assertFalse($features->recording_enabled);
    }

    public function test_feature_settings_is_the_single_switch_per_module(): void
    {
        $this->assertFalse(class_exists('App\Settings\ReferralSettings'));

        $features = app(FeatureSettings::class);
        $features->wallet_enabled = true;
        $features->referral_enabled = true;
        $features->save();

        $fresh = app()->make(FeatureSettings::class)->refresh();

        $this->assertTrue($fresh->wallet_enabled);
        $this->assertTrue($fresh->referral_enabled);
    }

    public function test_platform_foundation_settings_can_be_updated_directly(): void
    {
        $booking = app(BookingSettings::class);
        $booking->demo_duration_minutes = 45;
        $booking->reservation_expiry_minutes = 20;
        $booking->save();

        $freshBooking = app()->make(BookingSettings::class)->refresh();

        $this->assertSame(45, $freshBooking->demo_duration_minutes);
        $this->assertSame(20, $freshBooking->reservation_expiry_minutes);
    }

    public function test_platform_foundation_page_saves_settings(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

        $this->actingAs($admin);

        Livewire::test(PlatformFoundationSettingsPage::class)
            ->set('data.demo_duration_minutes', 40)
            ->set('data.reservation_expiry_minutes', 25)
            ->set('data.minimum_booking_notice_minutes', 180)
            ->set('data.maximum_advance_booking_days', 45)
            ->set('data.wallet_enabled', true)
            ->set('data.referral_enabled', true)
            ->set('data.recording_enabled', true)
            ->set('data.default_country', 'us')
            ->call('save')
            ->assertNotified('Platform foundation settings saved');

        $booking = app()->make(BookingSettings::class)->refresh();
        $this->assertSame(40, $booking->demo_duration_minutes);
        $this->assertSame(180, $booking->minimum_booking_notice_minutes);
        $this->assertSame(45, $booking->maximum_advance_booking_days);

        $features = app()->make(FeatureSettings::class)->refresh();
        $this->assertTrue($features->wallet_enabled);
        $this->assertTrue($features->referral_enabled);
        $this->assertTrue($features->recording_enabled);

        $this->assertSame('US', app()->make(LocalizationSettings::class)->refresh()->default_country);
    }

    public function test_platform_foundation_page_saves_instructor_settings(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

        $this->actingAs($admin);

        Livewire::test(PlatformFoundationSettingsPage::class)
            ->set('data.approval_required', false)
            ->set('data.featured_instructor_limit', 12)
            ->call('save')
            ->assertNotified('Platform foundation settings saved');

        $instructor = app()->make(InstructorSettings::class)->refresh();
        $this->assertFalse($instructor->approval_required);
        $this->assertSame(12, $instructor->featured_instructor_limit);
    }

    public function test_platform_foundation_page_no_longer_exposes_legacy_referral_reward_fields(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

        $this->actingAs($admin);

        // Campaign rules are the single reward source — the
        // page keeps the referral feature switch but none of the retired
        // float reward fields.
        Livewire::test(PlatformFoundationSettingsPage::class)
            ->assertSee('Referral')
            ->assertDontSee('Referrer Reward')
            ->assertDontSee('Referee Reward')
            ->assertDontSee('Unlock Days');
    }

    public function test_booking_window_fields_bind_to_the_fields_availability_rules_actually_read(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

        $this->actingAs($admin);

        Livewire::test(PlatformFoundationSettingsPage::class)
            ->set('data.minimum_booking_notice_minutes', 360)
            ->set('data.maximum_advance_booking_days', 45)
            ->call('save')
            ->assertNotified('Platform foundation settings saved');

        $booking = app()->make(BookingSettings::class)->refresh();

        $this->assertSame(360, $booking->minimum_booking_notice_minutes);
        $this->assertSame(45, $booking->maximum_advance_booking_days);
    }

    // ── Audit coverage across the five underlying settings classes ──

    public function test_saving_changes_across_multiple_domains_writes_one_event_per_changed_settings_class(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));
        $this->actingAs($admin);

        Livewire::test(PlatformFoundationSettingsPage::class)
            ->set('data.demo_duration_minutes', 40)
            ->set('data.reservation_expiry_minutes', 25)
            ->set('data.approval_required', false)
            ->set('data.default_country', 'us')
            ->set('data.wallet_enabled', true)
            ->call('save')
            ->assertNotified('Platform foundation settings saved');

        $events = Activity::query()
            ->where('log_name', 'settings')
            ->where('event', 'settings_updated')
            ->get()
            ->groupBy(fn (Activity $a) => $a->properties['settings_class']);

        $this->assertCount(1, $events->get(BookingSettings::class, collect()), 'Two changed Booking fields must produce exactly one Booking event.');
        $bookingChanged = $events[BookingSettings::class]->first()->properties['changed'];
        $this->assertArrayHasKey('demo_duration_minutes', $bookingChanged);
        $this->assertArrayHasKey('reservation_expiry_minutes', $bookingChanged);

        $this->assertCount(1, $events->get(InstructorSettings::class, collect()));
        $this->assertCount(1, $events->get(LocalizationSettings::class, collect()));
        $this->assertCount(1, $events->get(FeatureSettings::class, collect()));
    }

    public function test_saving_platform_foundation_settings_with_no_changes_creates_no_audit_events(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));
        $this->actingAs($admin);

        $booking = app(BookingSettings::class);
        $instructor = app(InstructorSettings::class);
        $localization = app(LocalizationSettings::class);
        $features = app(FeatureSettings::class);

        Livewire::test(PlatformFoundationSettingsPage::class)
            ->set('data.demo_duration_minutes', $booking->demo_duration_minutes)
            ->set('data.reservation_expiry_minutes', $booking->reservation_expiry_minutes)
            ->set('data.minimum_booking_notice_minutes', $booking->minimum_booking_notice_minutes)
            ->set('data.maximum_advance_booking_days', $booking->maximum_advance_booking_days)
            ->set('data.cancellation_window_hours', $booking->cancellation_window_hours)
            ->set('data.reschedule_limit', $booking->reschedule_limit)
            ->set('data.no_show_grace_minutes', $booking->no_show_grace_minutes)
            ->set('data.auto_completion_delay_minutes', $booking->auto_completion_delay_minutes)
            ->set('data.approval_required', $instructor->approval_required)
            ->set('data.profile_publish_requires_approval', $instructor->profile_publish_requires_approval)
            ->set('data.featured_instructor_limit', $instructor->featured_instructor_limit)
            ->set('data.availability_required_for_public_profile', $instructor->availability_required_for_public_profile)
            ->set('data.default_country', $localization->default_country)
            ->set('data.demo_lessons_enabled', $features->demo_lessons_enabled)
            ->set('data.wallet_enabled', $features->wallet_enabled)
            ->set('data.referral_enabled', $features->referral_enabled)
            ->set('data.waitlist_enabled', $features->waitlist_enabled)
            ->set('data.homework_enabled', $features->homework_enabled)
            ->set('data.recording_enabled', $features->recording_enabled)
            ->call('save');

        $this->assertDatabaseMissing('activity_log', [
            'log_name' => 'settings',
            'event' => 'settings_updated',
        ]);
    }

    public function test_unauthorized_user_cannot_save_platform_foundation_settings(): void
    {
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $student = User::factory()->create(['status' => 'active']);
        $student->assignRole('student');

        $this->actingAs($student)
            ->get('/admin/settings/platform-foundation')
            ->assertForbidden();

        $this->assertDatabaseMissing('activity_log', [
            'log_name' => 'settings',
            'event' => 'settings_updated',
        ]);
    }
}
