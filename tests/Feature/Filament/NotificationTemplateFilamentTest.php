<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\NotificationTemplates\NotificationTemplateResource;
use App\Filament\Resources\NotificationTemplates\Pages\EditNotificationTemplate;
use App\Filament\Resources\NotificationTemplates\Pages\ListNotificationTemplates;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Notifications\Templates\NotificationTemplateChannel;
use App\Notifications\Templates\NotificationTemplateKey;
use Database\Seeders\NotificationTemplatePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Permission-controlled Filament interface:
 * list/view/edit, no create/delete, restore-default, activate/
 * deactivate. Preview safety is covered at the renderer level
 * (NotificationTemplateRendererTest); this covers authorization and
 * the Filament-specific action wiring.
 */
final class NotificationTemplateFilamentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
    }

    private function permittedAdmin(): User
    {
        $this->seed(NotificationTemplatePermissionSeeder::class);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('manager');

        return $admin;
    }

    private function template(): NotificationTemplate
    {
        return NotificationTemplate::query()
            ->where('template_key', NotificationTemplateKey::WalletRechargeSucceeded->value)
            ->where('channel', NotificationTemplateChannel::Mail->value)
            ->firstOrFail();
    }

    public function test_resource_has_no_create_or_delete_capability(): void
    {
        $template = $this->template();

        $this->assertFalse(NotificationTemplateResource::canCreate());
        $this->assertFalse(NotificationTemplateResource::canDelete($template));
        $this->assertFalse(NotificationTemplateResource::canDeleteAny());
    }

    public function test_permitted_admin_can_view_the_list_and_edit_a_template(): void
    {
        $admin = $this->permittedAdmin();
        $template = $this->template();

        $this->actingAs($admin)->get(NotificationTemplateResource::getUrl('index'))->assertOk();
        $this->actingAs($admin)->get(NotificationTemplateResource::getUrl('edit', ['record' => $template]))->assertOk();
    }

    public function test_unauthorized_user_cannot_view_the_list_or_edit(): void
    {
        $unauthorized = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $template = $this->template();

        $this->actingAs($unauthorized)->get(NotificationTemplateResource::getUrl('index'))->assertForbidden();
        $this->actingAs($unauthorized)->get(NotificationTemplateResource::getUrl('edit', ['record' => $template]))->assertForbidden();
    }

    public function test_create_route_does_not_exist(): void
    {
        $admin = $this->permittedAdmin();

        $this->actingAs($admin)->get('/admin/notification-templates/create')->assertNotFound();
    }

    public function test_editing_through_the_form_persists_via_the_service_and_versions_it(): void
    {
        $admin = $this->permittedAdmin();
        $template = $this->template();

        Livewire::actingAs($admin)
            ->test(EditNotificationTemplate::class, ['record' => $template->getKey()])
            ->fillForm(['subject' => 'Edited subject', 'body' => 'Edited body {{amount}}'])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $template->fresh();
        $this->assertSame('Edited subject', $fresh->subject);
        $this->assertSame(2, $fresh->version);
        $this->assertSame($admin->id, $fresh->edited_by);
    }

    public function test_restore_default_table_action_clears_the_override(): void
    {
        $admin = $this->permittedAdmin();
        $template = $this->template();
        $template->update(['subject' => 'Custom', 'body' => 'Custom body']);

        Livewire::actingAs($admin)
            ->test(ListNotificationTemplates::class)
            ->callTableAction('restoreDefault', $template);

        $this->assertFalse($template->fresh()->hasOverride());
    }

    public function test_restore_default_action_is_hidden_when_there_is_no_override(): void
    {
        $admin = $this->permittedAdmin();
        $template = $this->template();

        Livewire::actingAs($admin)
            ->test(ListNotificationTemplates::class)
            ->assertTableActionHidden('restoreDefault', $template);
    }

    public function test_toggling_the_active_column_deactivates_via_the_service(): void
    {
        $admin = $this->permittedAdmin();
        $template = $this->template();

        Livewire::actingAs($admin)
            ->test(ListNotificationTemplates::class)
            ->call('updateTableColumnState', 'is_active', $template->getKey(), false);

        $this->assertFalse($template->fresh()->is_active);
    }

    public function test_list_query_count_does_not_scale_per_row_for_the_editor_relation(): void
    {
        $admin = $this->permittedAdmin();

        DB::enableQueryLog();
        $this->actingAs($admin)->get(NotificationTemplateResource::getUrl('index'))->assertOk();
        $withoutEditors = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Give every one of the 14 pre-seeded rows a distinct editor —
        // if the "editor.name" column caused an N+1, this would add one
        // query per row instead of a single eager-loaded query.
        NotificationTemplate::query()->get()->each(
            fn (NotificationTemplate $t) => $t->update(['edited_by' => User::factory()->create()->id]),
        );

        DB::enableQueryLog();
        $this->actingAs($admin)->get(NotificationTemplateResource::getUrl('index'))->assertOk();
        $withEditors = count(DB::getQueryLog());
        DB::disableQueryLog();

        // The template set is fixed (14 rows, never administrator-
        // growable), so the real risk is an N+1 on the editor relation
        // — 14 extra rows getting distinct editors must not add ~14
        // extra queries (one per row); a small constant increase from
        // Filament's own internals is acceptable, a per-row scale-up is not.
        $this->assertLessThan($withoutEditors + 10, $withEditors);
    }
}
