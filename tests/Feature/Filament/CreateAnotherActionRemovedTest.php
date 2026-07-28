<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\Faq\Pages\CreateFaqCategory;
use App\Filament\Resources\ReviewTags\Pages\CreateReviewTag;
use App\Models\ReviewTag;
use App\Models\User;
use Database\Seeders\ReviewPermissionSeeder;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionClass;
use Spatie\Permission\Models\Role;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Stage 2: "Create & create another" is removed centrally via
 * AdminPanelProvider::boot() calling CreateRecord::disableCreateAnother()
 * (see app/Providers/Filament/AdminPanelProvider.php). This asserts the
 * removal against real, rendered pages rather than the underlying static
 * property, plus a repo-wide coverage check that no Create page has
 * quietly re-enabled it.
 */
class CreateAnotherActionRemovedTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        // super_admin bypasses all policies via Gate::before() (AppServiceProvider).
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('super_admin');

        return $user;
    }

    public function test_no_create_page_overrides_the_disabled_default(): void
    {
        $finder = (new Finder)
            ->files()
            ->in(app_path('Filament/Resources'))
            ->path('/Pages\/Create.*\.php$/');

        $checked = 0;

        foreach ($finder as $file) {
            $declaredClass = $this->classFromPath($file->getRealPath());

            if ($declaredClass === null || ! is_subclass_of($declaredClass, CreateRecord::class)) {
                continue;
            }

            $reflection = new ReflectionClass($declaredClass);

            if ($reflection->hasProperty('canCreateAnother')) {
                $this->assertSame(
                    CreateRecord::class,
                    $reflection->getProperty('canCreateAnother')->getDeclaringClass()->getName(),
                    "{$declaredClass} redeclares \$canCreateAnother — it must inherit the panel-wide default instead.",
                );
            }

            if ($reflection->hasMethod('canCreateAnother')) {
                $this->assertSame(
                    CreateRecord::class,
                    $reflection->getMethod('canCreateAnother')->getDeclaringClass()->getName(),
                    "{$declaredClass} overrides canCreateAnother() — it must inherit the panel-wide default instead.",
                );
            }

            $checked++;
        }

        // A loose lower bound (not an exact count), matching the style of
        // AdminNavigationRegistryTest — the point is that every discovered
        // Create page was actually checked, not that the count is exact.
        $this->assertGreaterThanOrEqual(20, $checked);
    }

    public function test_review_tag_create_page_no_longer_offers_create_another(): void
    {
        $this->seed(ReviewPermissionSeeder::class);
        $admin = $this->superAdmin();

        $component = Livewire::actingAs($admin)->test(CreateReviewTag::class);

        $component->assertDontSee('Create & create another');
        $component->assertSee('Create');

        $component
            ->set('data.key', 'clear_examples')
            ->set('data.label', 'Clear Examples')
            ->set('data.applicable_modes', ['public_review'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('review_tags', ['key' => 'clear_examples']);
    }

    public function test_review_tag_create_page_validation_is_unchanged(): void
    {
        $this->seed(ReviewPermissionSeeder::class);
        ReviewTag::factory()->create(['key' => 'patient']);

        Livewire::actingAs($this->superAdmin())
            ->test(CreateReviewTag::class)
            ->set('data.key', 'patient')
            ->set('data.label', 'Patient Teacher')
            ->set('data.applicable_modes', ['public_review'])
            ->call('create')
            ->assertHasFormErrors(['key']);
    }

    public function test_faq_category_create_page_no_longer_offers_create_another(): void
    {
        $admin = $this->superAdmin();

        $component = Livewire::actingAs($admin)->test(CreateFaqCategory::class);

        $component->assertDontSee('Create & create another');
        $component->assertSee('Create');

        $component
            ->set('data.name', 'Billing')
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('faq_categories', ['name' => 'Billing']);
    }

    private function classFromPath(string $path): ?string
    {
        $relative = str_replace([app_path().DIRECTORY_SEPARATOR, '.php'], '', $path);
        $class = 'App\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

        return class_exists($class) ? $class : null;
    }
}
