<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pulse\Contracts\ResolvesUsers;
use Tests\TestCase;

/**
 * AppServiceProvider::configurePulse() overrides
 * Pulse's default user field resolver (Laravel\Pulse\Users), which
 * otherwise returns the user's email in the Usage card's `extra` field.
 */
class PulseUserResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolver_returns_only_safe_display_fields(): void
    {
        $user = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@sirieducation.com']);

        $resolver = app(ResolvesUsers::class);
        $resolver->load(collect([$user->id]));
        $resolved = $resolver->find($user->id);

        $this->assertSame('Ada Lovelace', $resolved->name);
        $this->assertFalse(property_exists($resolved, 'extra'), 'Resolver must never expose email via extra.');
        $this->assertFalse(property_exists($resolved, 'avatar'));
    }

    public function test_resolver_falls_back_to_identifier_when_name_is_blank(): void
    {
        $user = User::factory()->create(['name' => '', 'email' => 'noname@sirieducation.com']);

        $resolver = app(ResolvesUsers::class);
        $resolver->load(collect([$user->id]));
        $resolved = $resolver->find($user->id);

        $this->assertSame("User #{$user->id}", $resolved->name);
    }

    public function test_missing_or_deleted_user_resolves_safely(): void
    {
        $resolver = app(ResolvesUsers::class);
        $resolver->load(collect([999999999]));
        $resolved = $resolver->find(999999999);

        // A user Pulse can't resolve never reaches our custom callback —
        // Users::find() only invokes it when $user !== null — so this
        // falls back to Pulse's own safe "ID: {key}" label, never a crash.
        $this->assertSame('ID: 999999999', $resolved->name);
        $this->assertSame('', $resolved->extra);
    }
}
