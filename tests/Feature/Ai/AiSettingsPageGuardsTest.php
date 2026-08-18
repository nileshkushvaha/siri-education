<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Filament\Pages\Settings\AiSettingsPage;
use App\Models\User;
use App\Settings\AiSettings;
use App\Settings\FeatureSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The guards that make this page safe to hand an operator: the
 * configurations that look fine field-by-field but would silently do
 * nothing, spend wrongly, or fail on every request.
 */
class AiSettingsPageGuardsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

        return $admin;
    }

    /** @param array<string, mixed> $overrides */
    private function formState(array $overrides = []): array
    {
        return array_merge([
            'data.ai_enabled' => false,
            'data.provider' => 'fake',
            'data.openai_api_key' => null,
            'data.openai_organization' => null,
            'data.generation_model' => 'gpt-4.1',
            'data.fast_model' => 'gpt-4.1-mini',
            'data.embedding_model' => 'text-embedding-3-small',
            'data.moderation_model' => 'omni-moderation-latest',
            'data.request_timeout_seconds' => 30,
            'data.quality_insights_enabled' => false,
            'data.homework_assistant_enabled' => false,
            'data.lesson_summary_enabled' => false,
            'data.communication_moderation_enabled' => false,
            'data.daily_cost_limit' => 5,
            'data.monthly_cost_limit' => 100,
            'data.cost_currency' => 'USD',
            'data.budget_alert_threshold_percent' => 80,
            'data.model_pricing' => ['gpt-4.1' => '2.0/8.0'],
        ], $overrides);
    }

    // ── The enable guard ──────────────────────────────────────────────

    public function test_the_platform_cannot_be_enabled_on_openai_without_a_key(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(AiSettingsPage::class)
            ->set($this->formState([
                'data.ai_enabled' => true,
                'data.provider' => 'openai',
                'data.openai_api_key' => null,
            ]))
            ->call('save');

        // Nothing is saved at all — a half-applied enable is worse than
        // a refused one.
        $this->assertFalse(app(FeatureSettings::class)->refresh()->ai_enabled);
        $this->assertSame('fake', app(AiSettings::class)->refresh()->provider);
    }

    public function test_enabling_on_openai_succeeds_when_a_key_is_supplied_in_the_same_save(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(AiSettingsPage::class)
            ->set($this->formState([
                'data.ai_enabled' => true,
                'data.provider' => 'openai',
                'data.openai_api_key' => 'sk-live-key-value',
            ]))
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(app(FeatureSettings::class)->refresh()->ai_enabled);
    }

    public function test_enabling_on_openai_succeeds_when_a_key_is_already_stored(): void
    {
        $settings = app(AiSettings::class);
        $settings->openai_api_key = Crypt::encryptString('sk-existing-key');
        $settings->save();

        $this->actingAs($this->admin());

        Livewire::test(AiSettingsPage::class)
            ->set($this->formState(['data.ai_enabled' => true, 'data.provider' => 'openai']))
            ->call('save');

        $this->assertTrue(app(FeatureSettings::class)->refresh()->ai_enabled);
    }

    /** The fake provider needs no credential, so enabling on it is fine. */
    public function test_the_platform_can_be_enabled_on_the_fake_provider_without_a_key(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(AiSettingsPage::class)
            ->set($this->formState(['data.ai_enabled' => true, 'data.provider' => 'fake']))
            ->call('save');

        $this->assertTrue(app(FeatureSettings::class)->refresh()->ai_enabled);
    }

    // ── Pricing validation ────────────────────────────────────────────

    /**
     * The most dangerous silent failure on this page: an unparseable
     * price estimates at zero, so spend looks free and the ceiling is
     * never reached.
     */
    public function test_a_malformed_model_price_is_rejected(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(AiSettingsPage::class)
            ->set($this->formState(['data.model_pricing' => ['gpt-4.1' => 'two dollars']]))
            ->call('save')
            ->assertHasErrors('data.model_pricing');

        // The seeded price table is left exactly as it was — a rejected
        // save must not partially apply.
        $stored = app(AiSettings::class)->refresh()->model_pricing;
        $this->assertSame('2.0/8.0', $stored['gpt-4.1']);
        $this->assertNotContains('two dollars', $stored);
    }

    public function test_a_price_missing_its_output_half_is_rejected(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(AiSettingsPage::class)
            ->set($this->formState(['data.model_pricing' => ['gpt-4.1' => '2.0']]))
            ->call('save')
            ->assertHasErrors('data.model_pricing');
    }

    public function test_a_well_formed_price_is_accepted(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(AiSettingsPage::class)
            ->set($this->formState(['data.model_pricing' => ['gpt-5' => '1.25 / 10']]))
            ->call('save')
            ->assertHasNoErrors();

        $this->assertArrayHasKey('gpt-5', app(AiSettings::class)->refresh()->model_pricing);
    }

    // ── Limit sanity ──────────────────────────────────────────────────

    public function test_a_monthly_limit_below_the_daily_limit_is_rejected(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(AiSettingsPage::class)
            ->set($this->formState(['data.daily_cost_limit' => 50, 'data.monthly_cost_limit' => 10]))
            ->call('save')
            ->assertHasErrors('data.monthly_cost_limit');
    }

    public function test_blank_limits_are_allowed_and_mean_unlimited(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(AiSettingsPage::class)
            ->set($this->formState(['data.daily_cost_limit' => null, 'data.monthly_cost_limit' => null]))
            ->call('save')
            ->assertHasNoErrors();

        $settings = app(AiSettings::class)->refresh();

        $this->assertNull($settings->daily_cost_limit);
        $this->assertNull($settings->monthly_cost_limit);
    }

    // ── Model names ───────────────────────────────────────────────────

    public function test_a_model_name_containing_a_space_is_rejected(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(AiSettingsPage::class)
            ->set($this->formState(['data.generation_model' => 'gpt 4.1']))
            ->call('save')
            ->assertHasErrors('data.generation_model');
    }

    // ── Threshold round-trip ──────────────────────────────────────────

    /** Operators think in "warn me at 80%"; the guard stores a fraction. */
    public function test_the_alert_threshold_is_edited_as_a_percentage_and_stored_as_a_fraction(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(AiSettingsPage::class)
            ->set($this->formState(['data.budget_alert_threshold_percent' => 75]))
            ->call('save');

        $this->assertSame(0.75, app(AiSettings::class)->refresh()->budget_alert_threshold);

        Livewire::test(AiSettingsPage::class)->assertSet('data.budget_alert_threshold_percent', 75);
    }

    public function test_a_blank_threshold_disables_alerting(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(AiSettingsPage::class)
            ->set($this->formState(['data.budget_alert_threshold_percent' => null]))
            ->call('save');

        $this->assertNull(app(AiSettings::class)->refresh()->budget_alert_threshold);
    }

    // ── Credential handling ───────────────────────────────────────────

    /** A pasted key with stray whitespace is a classic silent 401. */
    public function test_a_pasted_key_is_trimmed_before_encryption(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(AiSettingsPage::class)
            ->set($this->formState(['data.openai_api_key' => "  sk-padded-key\n"]))
            ->call('save');

        $this->assertSame('sk-padded-key', Crypt::decryptString((string) app(AiSettings::class)->refresh()->openai_api_key));
    }

    // ── Status panel ──────────────────────────────────────────────────

    public function test_the_status_panel_warns_about_configurations_that_do_nothing(): void
    {
        $features = app(FeatureSettings::class);
        $features->ai_enabled = true;
        $features->save();

        $settings = app(AiSettings::class);
        $settings->provider = 'openai';
        $settings->openai_api_key = null;
        $settings->save();

        $this->actingAs($this->admin());

        Livewire::test(AiSettingsPage::class)
            ->assertSee('every AI request will be refused')
            ->assertSee('no capability is switched on');
    }

    public function test_the_status_panel_warns_when_a_selected_model_has_no_price(): void
    {
        $settings = app(AiSettings::class);
        $settings->generation_model = 'gpt-unpriced';
        $settings->save();

        $this->actingAs($this->admin());

        Livewire::test(AiSettingsPage::class)->assertSee('estimate as free');
    }

    public function test_the_status_panel_never_reveals_the_stored_key(): void
    {
        $settings = app(AiSettings::class);
        // On the fake provider the panel correctly reports that no
        // credential is needed, so this asserts against a real one.
        $settings->provider = 'openai';
        $settings->openai_api_key = Crypt::encryptString('sk-secret-value-123456');
        $settings->save();

        $this->actingAs($this->admin());

        $html = Livewire::test(AiSettingsPage::class)->html();

        $this->assertStringNotContainsString('sk-secret-value-123456', $html);
        $this->assertStringContainsString('Credential stored', $html);
    }
}
