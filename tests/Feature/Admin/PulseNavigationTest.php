<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The "Application Performance" nav link is a
 * convenience only — Pulse's own Authorize middleware on the /pulse route
 * is the actual security boundary (see PulseAccessTest), so this only
 * verifies the link's visibility follows the same permission.
 */
class PulseNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function pulseNavigationItem(): ?NavigationItem
    {
        $panel = Filament::getPanel('admin');

        foreach ($panel->getNavigationItems() as $item) {
            if ($item->getLabel() === 'Application Performance') {
                return $item;
            }
        }

        return null;
    }

    public function test_nav_item_is_registered_and_points_to_pulse_path(): void
    {
        $item = $this->pulseNavigationItem();

        $this->assertNotNull($item, 'Application Performance nav item not found.');
        $this->assertSame('/'.config('pulse.path', 'pulse'), $item->getUrl());
    }

    public function test_nav_item_hidden_for_unauthorized_user(): void
    {
        Permission::firstOrCreate(['name' => 'pulse.view', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);

        $manager = User::factory()->create(['status' => 'active']);
        $manager->assignRole('manager');

        $this->actingAs($manager);

        $this->assertFalse($this->pulseNavigationItem()->isVisible());
    }

    public function test_nav_item_visible_for_authorized_user(): void
    {
        Permission::firstOrCreate(['name' => 'pulse.view', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);

        $manager = User::factory()->create(['status' => 'active']);
        $manager->assignRole('manager');
        $manager->givePermissionTo('pulse.view');

        $this->actingAs($manager);

        $this->assertTrue($this->pulseNavigationItem()->isVisible());
    }

    public function test_nav_item_hidden_for_guest(): void
    {
        $this->assertFalse($this->pulseNavigationItem()->isVisible());
    }
}
