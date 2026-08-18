<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\Services\AiCredentialStore;
use App\Filament\Pages\Settings\AiSettingsPage;
use App\Models\Activity;
use App\Models\User;
use App\Settings\AiSettings;
use App\Settings\FeatureSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The security contract around the AI credential: encrypted at rest,
 * never redisplayed, never in a Livewire payload, never in the audit
 * trail, and decryptable from exactly one class.
 */
class AiSettingsSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const string API_KEY = 'sk-live-super-secret-key-987654321';

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
            'data.ai_enabled' => true,
            'data.provider' => 'fake',
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
            'data.model_pricing' => ['gpt-4.1' => '2.0/8.0'],
        ], $overrides);
    }

    public function test_the_api_key_is_encrypted_at_rest(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(AiSettingsPage::class)
            ->set($this->formState(['data.openai_api_key' => self::API_KEY]))
            ->call('save')
            ->assertHasNoErrors();

        $stored = app(AiSettings::class)->refresh()->openai_api_key;

        $this->assertNotSame(self::API_KEY, $stored);
        $this->assertSame(self::API_KEY, Crypt::decryptString((string) $stored));
    }

    public function test_the_api_key_is_never_returned_to_the_livewire_payload(): void
    {
        $settings = app(AiSettings::class);
        $settings->openai_api_key = Crypt::encryptString(self::API_KEY);
        $settings->save();

        $this->actingAs($this->admin());

        $component = Livewire::test(AiSettingsPage::class);

        $this->assertNull($component->get('data.openai_api_key'));
        $this->assertStringNotContainsString(self::API_KEY, json_encode($component->get('data'), JSON_THROW_ON_ERROR));
    }

    public function test_the_rendered_page_never_contains_the_key_or_its_ciphertext(): void
    {
        $settings = app(AiSettings::class);
        $settings->openai_api_key = Crypt::encryptString(self::API_KEY);
        $settings->save();

        $this->actingAs($this->admin());

        $html = Livewire::test(AiSettingsPage::class)->html();

        $this->assertStringNotContainsString(self::API_KEY, $html);
        $this->assertStringNotContainsString((string) app(AiSettings::class)->openai_api_key, $html);
        // The operator still learns whether a credential exists.
        $this->assertStringContainsString('Configured', $html);
    }

    public function test_a_blank_key_submission_keeps_the_stored_credential(): void
    {
        $settings = app(AiSettings::class);
        $settings->openai_api_key = Crypt::encryptString(self::API_KEY);
        $settings->save();

        $this->actingAs($this->admin());

        Livewire::test(AiSettingsPage::class)
            ->set($this->formState(['data.openai_api_key' => null]))
            ->call('save');

        $this->assertSame(self::API_KEY, Crypt::decryptString((string) app(AiSettings::class)->refresh()->openai_api_key));
    }

    public function test_the_audit_trail_records_that_a_key_changed_but_never_its_value(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(AiSettingsPage::class)
            ->set($this->formState(['data.openai_api_key' => self::API_KEY]))
            ->call('save');

        $activity = Activity::query()
            ->where('event', 'settings_updated')
            ->where('properties->settings_class', AiSettings::class)
            ->latest('id')
            ->firstOrFail();

        $encoded = json_encode($activity->properties, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString(self::API_KEY, $encoded);
        $this->assertStringNotContainsString((string) app(AiSettings::class)->refresh()->openai_api_key, $encoded);
        $this->assertSame('set', $activity->properties['changed']['openai_api_key']['action']);
    }

    public function test_settings_changes_are_audited(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(AiSettingsPage::class)
            ->set($this->formState(['data.generation_model' => 'gpt-5']))
            ->call('save');

        $this->assertDatabaseHas('activity_log', ['log_name' => 'ai', 'event' => 'settings_updated']);
        $this->assertTrue(app(FeatureSettings::class)->refresh()->ai_enabled);
    }

    public function test_the_connection_test_requires_its_own_permission_and_records_the_result(): void
    {
        $this->actingAs($this->admin());

        $settings = app(AiSettings::class);
        $settings->provider = 'fake';
        $settings->save();

        Livewire::test(AiSettingsPage::class)->call('checkHealth');

        $this->assertSame('healthy', app(AiSettings::class)->refresh()->last_health_status);
    }

    public function test_a_user_without_the_test_connection_permission_cannot_run_it(): void
    {
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole(Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']));
        // Settings access without the TestConnection permission.
        $manager->givePermissionTo(Permission::firstOrCreate(['name' => 'settings.ai.view', 'guard_name' => 'web']));

        $this->actingAs($manager);

        Livewire::test(AiSettingsPage::class)->call('checkHealth')->assertForbidden();
    }

    public function test_the_credential_store_is_the_only_reader_of_the_key_setting(): void
    {
        $callers = [];

        foreach ($this->phpFilesIn([app_path()]) as $path => $code) {
            if (! str_contains($code, 'openai_api_key')) {
                continue;
            }

            $callers[] = str_replace(app_path().'/', '', $path);
        }

        sort($callers);

        // The settings class declares it, the credential store decrypts
        // it, and the settings page writes it. Nothing else may name it.
        $this->assertSame([
            'Ai/Services/AiCredentialStore.php',
            'Filament/Pages/Settings/AiSettingsPage.php',
            'Settings/AiSettings.php',
        ], $callers);
    }

    public function test_the_key_is_not_exposed_through_the_settings_object_cast_to_json(): void
    {
        $settings = app(AiSettings::class);
        $settings->openai_api_key = Crypt::encryptString(self::API_KEY);
        $settings->save();

        // toArray() is what the audit diff and any future export reads;
        // it must carry ciphertext at worst, never plaintext.
        $this->assertStringNotContainsString(self::API_KEY, json_encode($settings->toArray(), JSON_THROW_ON_ERROR));
        $this->assertSame(self::API_KEY, app(AiCredentialStore::class)->openAiApiKey());
    }

    /** @return array<string, string> */
    private function phpFilesIn(array $directories): array
    {
        $files = [];

        foreach ($directories as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        return $files;
    }
}
