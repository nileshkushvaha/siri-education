<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Enums\ThemePreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ThemePreferenceTest extends TestCase
{
    use RefreshDatabase;

    private function student(): User
    {
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        $user->assignRole('student');

        return $user;
    }

    public function test_json_request_persists_theme_and_logs_audit_entry(): void
    {
        $user = $this->student();

        $this->actingAs($user)
            ->postJson(route('profile.theme.update'), ['theme' => 'dark'])
            ->assertOk()
            ->assertJson(['theme' => 'dark']);

        $this->assertSame(ThemePreference::Dark, $user->profile->fresh()->theme_preference);
        $this->assertDatabaseHas('activity_log', ['event' => 'theme_changed', 'causer_id' => $user->id]);
    }

    public function test_form_request_redirects_back_with_flash(): void
    {
        $user = $this->student();

        $this->actingAs($user)
            ->from(route('profile.show'))
            ->post(route('profile.theme.update'), ['theme' => 'system'])
            ->assertRedirect(route('profile.show'))
            ->assertSessionHas('success');

        $this->assertSame(ThemePreference::System, $user->profile->fresh()->theme_preference);
    }

    public function test_invalid_theme_is_rejected(): void
    {
        $user = $this->student();

        $this->actingAs($user)
            ->postJson(route('profile.theme.update'), ['theme' => 'sepia'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('theme');

        $this->assertNull($user->profile->fresh()->theme_preference);
    }

    public function test_guest_cannot_update_theme(): void
    {
        $this->postJson(route('profile.theme.update'), ['theme' => 'dark'])->assertUnauthorized();
    }

    public function test_portal_renders_light_by_default_and_dark_when_chosen(): void
    {
        $user = $this->student();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-portal-theme="light"', false)
            ->assertDontSee('<html lang="en" class="dark"', false);

        $user->profile->update(['theme_preference' => ThemePreference::Dark]);

        $this->actingAs($user->fresh())->get(route('dashboard'))
            ->assertOk()
            ->assertSee('class="dark"', false)
            ->assertSee('data-portal-theme="dark"', false);
    }

    public function test_public_pages_keep_fixed_dark_kit(): void
    {
        $this->get(route('home'))->assertOk()->assertSee('class="dark"', false);
    }
}
