<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Content\Redirects\Enums\RedirectType;
use App\Content\Redirects\Services\RedirectService;
use App\Filament\Resources\Redirects\Pages\CreateRedirect;
use App\Filament\Resources\Redirects\Pages\ListRedirects;
use App\Filament\Resources\Redirects\RedirectResource;
use App\Models\Redirect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Permission-controlled list/create/edit/view
 * plus activate/deactivate. No delete action anywhere (SRS
 * historical-auditability rule); every mutation flows through
 * RedirectService, never a direct model write from the resource.
 */
final class RedirectFilamentTest extends TestCase
{
    use RefreshDatabase;

    private function permittedAdmin(array $permissions): User
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $admin->assignRole('manager');

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $admin->givePermissionTo($permissions);

        return $admin;
    }

    private function makeRedirect(string $source = '/old-page'): Redirect
    {
        $creator = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        return app(RedirectService::class)->create($creator, [
            'source_path' => $source,
            'target_path' => '/about-us',
            'type' => RedirectType::Permanent,
        ]);
    }

    public function test_resource_has_no_delete_capability(): void
    {
        $redirect = $this->makeRedirect();

        $this->assertFalse(RedirectResource::canDelete($redirect));
        $this->assertFalse(RedirectResource::canDeleteAny());
    }

    public function test_permitted_admin_can_view_the_redirect_list(): void
    {
        $admin = $this->permittedAdmin(['ViewAny:Redirect', 'View:Redirect']);
        $this->makeRedirect();

        $this->actingAs($admin)
            ->get(RedirectResource::getUrl('index'))
            ->assertOk();
    }

    public function test_unauthorized_user_cannot_view_the_redirect_list(): void
    {
        $unauthorized = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $this->actingAs($unauthorized)
            ->get(RedirectResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_permitted_admin_can_create_a_redirect_through_the_service(): void
    {
        $admin = $this->permittedAdmin(['ViewAny:Redirect', 'Create:Redirect']);

        $this->actingAs($admin);

        Livewire::test(CreateRedirect::class)
            ->fillForm([
                'source_path' => '/legacy-page',
                'target_path' => '/about-us',
                'type' => RedirectType::Permanent->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $redirect = Redirect::query()->where('source_path', '/legacy-page')->sole();
        $this->assertSame('/about-us', $redirect->target_path);
        $this->assertSame($admin->id, $redirect->created_by);
    }

    public function test_create_form_surfaces_a_service_validation_error_without_creating_a_row(): void
    {
        $admin = $this->permittedAdmin(['ViewAny:Redirect', 'Create:Redirect']);
        $this->makeRedirect('/duplicate-source');

        $this->actingAs($admin);

        Livewire::test(CreateRedirect::class)
            ->fillForm([
                'source_path' => '/duplicate-source',
                'target_path' => '/somewhere-else',
                'type' => RedirectType::Permanent->value,
            ])
            ->call('create');

        $this->assertSame(1, Redirect::query()->where('source_path', '/duplicate-source')->count());
    }

    public function test_permitted_admin_can_activate_and_deactivate_a_redirect(): void
    {
        $admin = $this->permittedAdmin(['ViewAny:Redirect', 'Activate:Redirect', 'Deactivate:Redirect']);
        $redirect = $this->makeRedirect();

        $this->actingAs($admin);

        Livewire::test(ListRedirects::class)->callTableAction('deactivate', $redirect);
        $this->assertFalse($redirect->fresh()->is_active);

        Livewire::test(ListRedirects::class)->callTableAction('activate', $redirect->fresh());
        $this->assertTrue($redirect->fresh()->is_active);
    }

    public function test_an_admin_without_deactivate_permission_cannot_deactivate(): void
    {
        $admin = $this->permittedAdmin(['ViewAny:Redirect']);
        $redirect = $this->makeRedirect();

        $this->actingAs($admin);

        Livewire::test(ListRedirects::class)->assertTableActionHidden('deactivate', $redirect);

        $this->assertTrue($redirect->fresh()->is_active);
    }

    public function test_list_query_stays_bounded_as_redirect_count_grows(): void
    {
        $admin = $this->permittedAdmin(['ViewAny:Redirect', 'View:Redirect']);

        for ($i = 0; $i < 20; $i++) {
            $this->makeRedirect("/old-{$i}");
        }

        DB::enableQueryLog();
        $this->actingAs($admin)->get(RedirectResource::getUrl('index'))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(60, $count);
    }
}
