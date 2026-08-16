<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Widgets\RelatedResourceLinksWidget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Guards the "Snapshot missing on Livewire component" regression.
 *
 * The widget view once wrapped its root element in `@if`. Filament lazy-loads widgets by
 * default, so Livewire rendered a placeholder root first and then morphed the real view
 * in — and a conditional root cannot be reconciled against that placeholder. `wire:id`
 * ended up on an inner element with no `wire:snapshot`, and Livewire's JS threw as soon
 * as Alpine's mutation observer walked the injected node.
 */
class RelatedResourceLinksWidgetTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): User
    {
        $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole($role);

        $this->actingAs($admin);

        return $admin;
    }

    public function test_the_widget_is_not_lazy_so_no_placeholder_morph_can_drop_the_snapshot(): void
    {
        $this->assertFalse(
            RelatedResourceLinksWidget::isLazy(),
            'A lazy placeholder morph is what dropped the snapshot; keep this widget eager.'
        );
    }

    public function test_the_widget_root_element_carries_both_wire_id_and_snapshot(): void
    {
        $this->actingAsSuperAdmin();

        $html = $this->get('/admin/academic/subjects')->assertOk()->getContent();

        preg_match_all(
            '#<[a-zA-Z0-9]+[^>]*wire:name="[^"]*RelatedResourceLinksWidget"[^>]*>#',
            $html,
            $matches
        );

        $this->assertCount(1, $matches[0], 'Expected exactly one widget root element.');

        $root = $matches[0][0];
        $this->assertStringContainsString('wire:id', $root);
        $this->assertStringContainsString('wire:snapshot', $root);
    }

    public function test_the_navigation_renders_server_side_rather_than_via_a_lazy_placeholder(): void
    {
        $this->actingAsSuperAdmin();

        $html = $this->get('/admin/academic/subjects')->assertOk()->getContent();

        // Absent from the initial HTML means the widget deferred to a placeholder.
        $this->assertStringContainsString('aria-label="Related pages"', $html);
        $this->assertStringContainsString('Academic Categories', $html);
    }

    public function test_every_page_using_the_related_links_trait_renders_the_widget_cleanly(): void
    {
        $this->actingAsSuperAdmin();

        foreach ([
            '/admin/academic/subjects',
            '/admin/academic/subject-topics',
            '/admin/academic/academic-categories',
        ] as $path) {
            $html = $this->get($path)->assertOk()->getContent();

            preg_match_all(
                '#<[a-zA-Z0-9]+[^>]*wire:name="[^"]*RelatedResourceLinksWidget"[^>]*>#',
                $html,
                $matches
            );

            $this->assertCount(1, $matches[0], "Widget root missing or duplicated on {$path}");
            $this->assertStringContainsString('wire:snapshot', $matches[0][0], "Snapshot missing on {$path}");
        }
    }
}
