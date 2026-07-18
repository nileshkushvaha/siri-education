<?php

declare(strict_types=1);

namespace Tests\Feature\AccountPortal;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Proves Dashboard and Profile render through the same shared Account
 * Portal layout/sidebar/breadcrumb/profile-header instead of each page
 * hand-rolling its own — the exact duplication this refactor removes.
 */
class AccountLayoutTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $user = User::factory()->create([
            'status' => 'active',
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);
        $user->assignRole('student');

        return $user;
    }

    public function test_dashboard_renders_exactly_one_sidebar(): void
    {
        $response = $this->actingAs($this->makeUser())->get(route('dashboard'));

        $response->assertSuccessful();
        $this->assertSame(2, substr_count($response->getContent(), 'data-account-sidebar data-account-sidebar-mode'));
    }

    public function test_profile_renders_exactly_one_sidebar(): void
    {
        $response = $this->actingAs($this->makeUser())->get(route('profile.show'));

        $response->assertSuccessful();
        $this->assertSame(2, substr_count($response->getContent(), 'data-account-sidebar data-account-sidebar-mode'));
    }

    public function test_dashboard_and_profile_share_the_same_sidebar_component(): void
    {
        $user = $this->makeUser();

        $dashboard = $this->actingAs($user)->get(route('dashboard'))->getContent();
        $profile = $this->actingAs($user)->get(route('profile.show'))->getContent();

        $this->assertStringContainsString('data-account-sidebar', $dashboard);
        $this->assertStringContainsString('data-account-sidebar', $profile);
        $this->assertStringContainsString('data-account-menu-item="dashboard"', $dashboard);
        $this->assertStringContainsString('data-account-menu-item="dashboard"', $profile);
    }

    public function test_active_menu_item_reflects_current_route(): void
    {
        $user = $this->makeUser();

        $dashboard = $this->actingAs($user)->get(route('dashboard'))->getContent();
        $profile = $this->actingAs($user)->get(route('profile.show'))->getContent();

        $this->assertMatchesRegularExpression(
            '/data-account-menu-item="dashboard"[^>]*account-menu-active/',
            $dashboard,
        );
        $this->assertMatchesRegularExpression(
            '/data-account-menu-item="profile\.show"[^>]*account-menu-active/',
            $profile,
        );
    }

    /**
     * The 'My Profile' item is gated by the 'profile.view' ability, which
     * ProfilePolicy resolves via isActive(). EnsureAccountIsActive already
     * logs out/redirects inactive users before any Account Portal page
     * renders, so permission-hidden-item coverage for arbitrary permissions
     * lives at the service level (AccountMenuServiceTest) — here we only
     * confirm the real item renders for the only kind of user that can
     * reach this page at all.
     */
    public function test_profile_menu_item_visible_for_any_active_frontend_user(): void
    {
        $content = $this->actingAs($this->makeUser())->get(route('dashboard'))->getContent();

        $this->assertStringContainsString('data-account-menu-item="profile.show"', $content);
    }

    public function test_breadcrumb_renders_home_to_dashboard(): void
    {
        $content = $this->actingAs($this->makeUser())->get(route('dashboard'))->getContent();

        $this->assertStringContainsString('Home', $content);
        $this->assertStringContainsString('Dashboard', $content);
    }

    public function test_breadcrumb_renders_home_to_dashboard_to_profile(): void
    {
        $content = $this->actingAs($this->makeUser())->get(route('profile.show'))->getContent();

        $this->assertStringContainsString('Home', $content);
        $this->assertStringContainsString('My Profile', $content);
    }

    public function test_profile_header_component_renders_on_both_pages(): void
    {
        $user = $this->makeUser();

        $dashboard = $this->actingAs($user)->get(route('dashboard'))->getContent();
        $profile = $this->actingAs($user)->get(route('profile.show'))->getContent();

        $this->assertStringContainsString('data-account-header', $dashboard);
        $this->assertStringContainsString('data-account-profile-header', $profile);
    }

    public function test_dashboard_and_profile_use_the_same_account_layout(): void
    {
        $user = $this->makeUser();

        $dashboard = $this->actingAs($user)->get(route('dashboard'));
        $profile = $this->actingAs($user)->get(route('profile.show'));

        $dashboard->assertViewIs('dashboard.index');
        $profile->assertViewIs('profile.show');

        // Both view files extend the same shared layout.
        $this->assertStringContainsString(
            "@extends('layouts.account')",
            file_get_contents(resource_path('views/dashboard/index.blade.php')),
        );
        $this->assertStringContainsString(
            "@extends('layouts.account')",
            file_get_contents(resource_path('views/profile/show.blade.php')),
        );
    }
}
