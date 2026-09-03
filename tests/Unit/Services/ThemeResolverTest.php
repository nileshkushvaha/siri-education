<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ThemePreference;
use App\Models\User;
use App\Services\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ThemeResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_resolves_to_light(): void
    {
        $resolver = new ThemeResolver;

        $this->assertSame(ThemePreference::Light, $resolver->resolve(null));
        $this->assertSame('', $resolver->htmlClass(null));
    }

    public function test_user_without_stored_preference_resolves_to_light(): void
    {
        $user = User::factory()->create();

        $resolver = new ThemeResolver;

        $this->assertSame(ThemePreference::Light, $resolver->resolve($user));
        $this->assertSame('', $resolver->htmlClass($user));
    }

    public function test_dark_preference_yields_dark_html_class(): void
    {
        $user = User::factory()->create();
        $user->profile->update(['theme_preference' => ThemePreference::Dark]);

        $resolver = new ThemeResolver;

        $this->assertSame(ThemePreference::Dark, $resolver->resolve($user->fresh()));
        $this->assertSame('dark', $resolver->htmlClass($user->fresh()));
    }

    public function test_system_preference_leaves_class_to_client_bootstrap(): void
    {
        $user = User::factory()->create();
        $user->profile->update(['theme_preference' => ThemePreference::System]);

        $this->assertSame('', (new ThemeResolver)->htmlClass($user->fresh()));
    }
}
