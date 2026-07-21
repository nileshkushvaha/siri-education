<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Filament\Pages\Settings\ReviewQualitySettingsPage;
use App\Models\Activity;
use App\Models\User;
use App\Settings\ReviewSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 17U.2 §§4-6 — the sole runtime-configuration surface for
 * ReviewSettings: field persistence across every whitelisted section,
 * business-rule validation with no partial persistence on failure,
 * separate view/update permissions re-checked at execution time, and
 * an audit trail (including a mandatory reason for a master-switch or
 * moderation-model change).
 */
class ReviewQualitySettingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // ReviewSettings ships disabled-safe (reviews_enabled defaults to
        // false) — establish an already-enabled baseline matching
        // validState() so master-switch/moderation-model "changed" tests
        // reflect a genuine value change, not a same-value no-op.
        $settings = app(ReviewSettings::class);
        $settings->reviews_enabled = true;
        $settings->moderation_model = 'risk_based';
        $settings->save();
    }

    private function admin(): User
    {
        Permission::firstOrCreate(['name' => 'settings.reviews_quality.view', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'settings.reviews_quality.update', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web'])
            ->givePermissionTo(['settings.reviews_quality.view', 'settings.reviews_quality.update']);

        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('manager');

        return $admin;
    }

    private function viewOnlyManager(): User
    {
        Permission::firstOrCreate(['name' => 'settings.reviews_quality.view', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web'])
            ->givePermissionTo('settings.reviews_quality.view');

        $manager = User::factory()->create(['status' => 'active']);
        $manager->assignRole('manager');

        return $manager;
    }

    /** @return array<string, mixed> */
    private function validState(array $overrides = []): array
    {
        return array_merge([
            'reviews_enabled' => true,
            'paid_lesson_reviews_enabled' => true,
            'demo_review_policy' => 'private_only',
            'review_window_days' => 14,
            'change_reason' => null,
            'rating_min' => 1,
            'rating_max' => 5,
            'written_review_required' => false,
            'review_min_length' => 10,
            'review_max_length' => 2000,
            'rating_dimensions_enabled' => true,
            'review_max_tags' => 5,
            'moderation_model' => 'risk_based',
            'auto_publish_clean_reviews' => true,
            'public_review_identity_mode' => 'first_name_initial',
            'review_reporting_enabled' => true,
            'review_editing_enabled' => true,
            'review_edit_window_hours' => 24,
            'quality_alerts_enabled' => false,
            'low_rating_threshold' => 2,
            'single_low_rating_alert_enabled' => true,
            'repeated_low_rating_count' => 3,
            'repeated_low_rating_window_days' => 30,
            'repeated_no_show_count' => 2,
            'repeated_no_show_window_days' => 30,
            'repeated_cancellation_count' => 3,
            'repeated_cancellation_window_days' => 30,
            'quality_dashboard_low_rating_threshold' => 2.5,
            'quality_dashboard_high_rating_threshold' => 4.5,
            'quality_dashboard_min_review_count' => 3,
            'review_channel_email_enabled' => true,
            'review_channel_whatsapp_enabled' => false,
            'review_channel_sms_enabled' => false,
        ], $overrides);
    }

    private function fillWith($test, array $state)
    {
        foreach ($state as $key => $value) {
            $test->set("data.{$key}", $value);
        }

        return $test;
    }

    // ── Access ────────────────────────────────────────────────────────

    public function test_page_renders_for_a_user_with_the_view_permission(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/settings/reviews-quality')
            ->assertOk()
            ->assertSee('Reviews & Quality Settings');
    }

    public function test_page_is_forbidden_for_a_user_without_the_view_permission(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $this->actingAs($user)->get('/admin/settings/reviews-quality')->assertForbidden();
    }

    public function test_super_admin_may_access_without_the_explicit_permission(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('super_admin');

        $this->actingAs($admin)
            ->get('/admin/settings/reviews-quality')
            ->assertOk();
    }

    // ── Persistence across every whitelisted section ─────────────────

    public function test_save_persists_every_field_group(): void
    {
        $this->actingAs($this->admin());

        $state = $this->validState([
            'paid_lesson_reviews_enabled' => false,
            'demo_review_policy' => 'public',
            'review_window_days' => 21,
            'rating_min' => 0,
            'rating_max' => 10,
            'written_review_required' => true,
            'moderation_model' => 'pre_moderation',
            'change_reason' => 'Switching to stricter pre-moderation for a trial period.',
            'quality_alerts_enabled' => true,
            'low_rating_threshold' => 3,
            'quality_dashboard_low_rating_threshold' => 3.0,
            'quality_dashboard_high_rating_threshold' => 8.0,
            'review_channel_email_enabled' => false,
        ]);

        $this->fillWith(Livewire::test(ReviewQualitySettingsPage::class), $state)
            ->call('save')
            ->assertNotified('Reviews & Quality settings saved');

        $settings = app(ReviewSettings::class)->refresh();
        $this->assertFalse($settings->paid_lesson_reviews_enabled);
        $this->assertSame('public', $settings->demo_review_policy);
        $this->assertSame(21, $settings->review_window_days);
        $this->assertSame(0, $settings->rating_min);
        $this->assertSame(10, $settings->rating_max);
        $this->assertTrue($settings->written_review_required);
        $this->assertSame('pre_moderation', $settings->moderation_model);
        $this->assertTrue($settings->quality_alerts_enabled);
        $this->assertSame(3, $settings->low_rating_threshold);
        $this->assertSame(3.0, $settings->quality_dashboard_low_rating_threshold);
        $this->assertSame(8.0, $settings->quality_dashboard_high_rating_threshold);
        $this->assertFalse($settings->review_channel_email_enabled);
    }

    // ── Validation: bounds and relational checks ──────────────────────

    public function test_rating_min_must_be_less_than_rating_max(): void
    {
        $this->actingAs($this->admin());
        $before = app(ReviewSettings::class)->rating_max;

        $this->fillWith(Livewire::test(ReviewQualitySettingsPage::class), $this->validState(['rating_min' => 5, 'rating_max' => 5]))
            ->call('save')
            ->assertNotified('Reviews & Quality settings were not saved');

        $this->assertSame($before, app(ReviewSettings::class)->refresh()->rating_max);
    }

    public function test_min_written_length_must_not_exceed_max_written_length(): void
    {
        $this->actingAs($this->admin());
        $before = app(ReviewSettings::class)->review_max_length;

        $this->fillWith(Livewire::test(ReviewQualitySettingsPage::class), $this->validState(['review_min_length' => 3000, 'review_max_length' => 2000]))
            ->call('save')
            ->assertNotified('Reviews & Quality settings were not saved');

        $this->assertSame($before, app(ReviewSettings::class)->refresh()->review_max_length);
    }

    public function test_low_rating_threshold_must_fall_within_the_rating_scale(): void
    {
        $this->actingAs($this->admin());

        $this->fillWith(Livewire::test(ReviewQualitySettingsPage::class), $this->validState(['low_rating_threshold' => 99]))
            ->call('save')
            ->assertNotified('Reviews & Quality settings were not saved');

        $this->assertSame(2, app(ReviewSettings::class)->refresh()->low_rating_threshold);
    }

    public function test_dashboard_low_threshold_must_be_less_than_high_threshold(): void
    {
        $this->actingAs($this->admin());

        $this->fillWith(Livewire::test(ReviewQualitySettingsPage::class), $this->validState([
            'quality_dashboard_low_rating_threshold' => 4.5,
            'quality_dashboard_high_rating_threshold' => 4.5,
        ]))
            ->call('save')
            ->assertNotified('Reviews & Quality settings were not saved');

        $this->assertSame(2.5, app(ReviewSettings::class)->refresh()->quality_dashboard_low_rating_threshold);
    }

    public function test_dashboard_thresholds_must_fall_within_the_rating_scale(): void
    {
        $this->actingAs($this->admin());

        $this->fillWith(Livewire::test(ReviewQualitySettingsPage::class), $this->validState([
            'rating_min' => 1,
            'rating_max' => 5,
            'quality_dashboard_high_rating_threshold' => 50.0,
        ]))
            ->call('save')
            ->assertNotified('Reviews & Quality settings were not saved');

        $this->assertSame(4.5, app(ReviewSettings::class)->refresh()->quality_dashboard_high_rating_threshold);
    }

    public function test_repeated_counts_must_be_at_least_one(): void
    {
        $this->actingAs($this->admin());

        // Caught by the TextInput's own minValue(1) — an inline field
        // error, before this ever reaches the custom business-rule
        // check or persists anything.
        $this->fillWith(Livewire::test(ReviewQualitySettingsPage::class), $this->validState(['repeated_low_rating_count' => 0]))
            ->call('save')
            ->assertHasErrors(['data.repeated_low_rating_count']);

        $this->assertSame(3, app(ReviewSettings::class)->refresh()->repeated_low_rating_count);
    }

    public function test_window_days_must_be_at_least_one(): void
    {
        $this->actingAs($this->admin());

        $this->fillWith(Livewire::test(ReviewQualitySettingsPage::class), $this->validState(['review_window_days' => 0]))
            ->call('save')
            ->assertHasErrors(['data.review_window_days']);

        $this->assertSame(14, app(ReviewSettings::class)->refresh()->review_window_days);
    }

    public function test_demo_review_policy_must_be_a_recognized_enum_value(): void
    {
        $this->actingAs($this->admin());

        // Caught by the Select's own options() constraint — inline
        // field error, never reaches the custom check either.
        $this->fillWith(Livewire::test(ReviewQualitySettingsPage::class), $this->validState(['demo_review_policy' => 'not_a_real_policy']))
            ->call('save')
            ->assertHasErrors(['data.demo_review_policy']);

        $this->assertSame('private_only', app(ReviewSettings::class)->refresh()->demo_review_policy);
    }

    public function test_moderation_model_must_be_a_recognized_enum_value(): void
    {
        $this->actingAs($this->admin());

        $this->fillWith(Livewire::test(ReviewQualitySettingsPage::class), $this->validState([
            'moderation_model' => 'not_a_real_model',
            'change_reason' => 'Testing an invalid value.',
        ]))
            ->call('save')
            ->assertHasErrors(['data.moderation_model']);

        $this->assertSame('risk_based', app(ReviewSettings::class)->refresh()->moderation_model);
    }

    public function test_a_failed_save_persists_nothing_even_when_only_one_field_is_invalid(): void
    {
        $this->actingAs($this->admin());

        // Everything else in this payload is a real, valid change — only
        // rating_min/rating_max is broken. None of it may land.
        $this->fillWith(Livewire::test(ReviewQualitySettingsPage::class), $this->validState([
            'rating_min' => 10,
            'rating_max' => 1,
            'review_window_days' => 99,
            'quality_alerts_enabled' => true,
        ]))
            ->call('save')
            ->assertNotified('Reviews & Quality settings were not saved');

        $settings = app(ReviewSettings::class)->refresh();
        $this->assertSame(14, $settings->review_window_days);
        $this->assertFalse($settings->quality_alerts_enabled);
    }

    // ── Reason requirement for master-switch / moderation-model changes ──

    public function test_disabling_reviews_enabled_without_a_reason_is_rejected(): void
    {
        $this->actingAs($this->admin());

        $this->fillWith(Livewire::test(ReviewQualitySettingsPage::class), $this->validState(['reviews_enabled' => false, 'change_reason' => null]))
            ->call('save')
            ->assertNotified('Reviews & Quality settings were not saved');

        $this->assertTrue(app(ReviewSettings::class)->refresh()->reviews_enabled);
    }

    public function test_disabling_reviews_enabled_with_a_reason_succeeds_and_is_audited(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $this->fillWith(Livewire::test(ReviewQualitySettingsPage::class), $this->validState([
            'reviews_enabled' => false,
            'change_reason' => 'Pausing reviews during a platform-wide content audit.',
        ]))
            ->call('save')
            ->assertNotified('Reviews & Quality settings saved');

        $this->assertFalse(app(ReviewSettings::class)->refresh()->reviews_enabled);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'reviews',
            'event' => 'settings_updated',
            'causer_id' => $admin->id,
        ]);

        $activity = Activity::where('log_name', 'reviews')->where('event', 'settings_updated')->latest('id')->firstOrFail();
        $this->assertSame('Pausing reviews during a platform-wide content audit.', $activity->properties->get('reason'));
        $this->assertFalse($activity->properties->get('changed')['reviews_enabled']['to']);
    }

    public function test_changing_moderation_model_without_a_reason_is_rejected(): void
    {
        $this->actingAs($this->admin());

        $this->fillWith(Livewire::test(ReviewQualitySettingsPage::class), $this->validState(['moderation_model' => 'post_moderation', 'change_reason' => null]))
            ->call('save')
            ->assertNotified('Reviews & Quality settings were not saved');

        $this->assertSame('risk_based', app(ReviewSettings::class)->refresh()->moderation_model);
    }

    public function test_saving_unrelated_fields_without_touching_the_switch_or_model_needs_no_reason(): void
    {
        $this->actingAs($this->admin());

        $this->fillWith(Livewire::test(ReviewQualitySettingsPage::class), $this->validState(['review_window_days' => 30, 'change_reason' => null]))
            ->call('save')
            ->assertNotified('Reviews & Quality settings saved');

        $this->assertSame(30, app(ReviewSettings::class)->refresh()->review_window_days);
    }

    // ── Stub-provider warnings ─────────────────────────────────────────

    public function test_enabling_whatsapp_channel_saves_but_warns_about_the_stub_provider(): void
    {
        $this->actingAs($this->admin());

        $this->fillWith(Livewire::test(ReviewQualitySettingsPage::class), $this->validState(['review_channel_whatsapp_enabled' => true]))
            ->call('save')
            ->assertNotified('Reviews & Quality settings saved');

        $this->assertTrue(app(ReviewSettings::class)->refresh()->review_channel_whatsapp_enabled);
    }

    // ── Separate view vs. update permission, re-checked at execution time ──

    public function test_a_view_only_manager_cannot_save_even_by_directly_calling_the_action(): void
    {
        $this->actingAs($this->viewOnlyManager());
        $before = app(ReviewSettings::class)->review_window_days;

        $this->fillWith(Livewire::test(ReviewQualitySettingsPage::class), $this->validState(['review_window_days' => 45]))
            ->call('save')
            ->assertNotified('You do not have permission to update these settings.');

        $this->assertSame($before, app(ReviewSettings::class)->refresh()->review_window_days);
    }

    // ── Phase 24S: no-op save creates no audit event ─────────────────────

    public function test_saving_with_no_changes_creates_no_audit_event(): void
    {
        $this->actingAs($this->admin());

        $this->fillWith(Livewire::test(ReviewQualitySettingsPage::class), $this->validState())
            ->call('save')
            ->assertNotified('Reviews & Quality settings saved');

        $this->assertDatabaseMissing('activity_log', [
            'log_name' => 'reviews',
            'event' => 'settings_updated',
        ]);
    }
}
