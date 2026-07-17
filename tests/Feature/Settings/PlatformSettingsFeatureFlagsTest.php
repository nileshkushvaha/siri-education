<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Filament\Pages\Settings\PlatformFoundationSettingsPage;
use App\Models\User;
use App\Settings\BookingSettings;
use App\Settings\FeatureSettings;
use App\Settings\InstructorSettings;
use App\Settings\LocalizationSettings;
use App\Settings\MeetingSettings;
use App\Settings\WalletSettings;
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
        $wallet = app(WalletSettings::class);
        $meeting = app(MeetingSettings::class);
        $instructor = app(InstructorSettings::class);
        $localization = app(LocalizationSettings::class);
        $features = app(FeatureSettings::class);

        $this->assertSame(30, $booking->demo_duration_minutes);
        $this->assertSame(120, $booking->minimum_booking_notice_minutes);
        $this->assertSame(90, $booking->maximum_advance_booking_days);
        $this->assertSame(100.0, $wallet->minimum_recharge_amount);
        $this->assertSame('manual', $meeting->default_provider);
        $this->assertTrue($instructor->approval_required);
        $this->assertSame('IN', $localization->default_country);
        $this->assertTrue($features->demo_lessons_enabled);
        $this->assertFalse($features->wallet_enabled);
        $this->assertFalse($features->referral_enabled);
        $this->assertFalse($features->recording_enabled);
    }

    public function test_feature_settings_is_the_single_switch_per_module(): void
    {
        // WalletSettings never redeclares its own "enabled" —
        // FeatureSettings is the only on/off switch. ReferralSettings was
        // retired entirely in Phase 19C: referral_campaigns is the single
        // source of reward rules, so no settings class may compete.
        $this->assertFalse(property_exists(app(WalletSettings::class), 'enabled'));
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
            ->set('data.minimum_recharge_amount', 250)
            ->set('data.maximum_recharge_amount', 25000)
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

        $this->assertSame(250.0, app()->make(WalletSettings::class)->refresh()->minimum_recharge_amount);

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

        // Phase 19C: campaign rules are the single reward source — the
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
}
