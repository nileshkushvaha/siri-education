<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\ReviewTags\Pages\CreateReviewTag;
use App\Filament\Resources\ReviewTags\Pages\EditReviewTag;
use App\Filament\Resources\ReviewTags\Pages\ListReviewTags;
use App\Filament\Resources\ReviewTags\ReviewTagResource;
use App\Filament\Resources\ReviewTags\Tables\ReviewTagsTable;
use App\Models\ReviewTag;
use App\Models\User;
use App\Reviews\Enums\LessonReviewEligibilityMode;
use Database\Seeders\ReviewPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Review tag administration via `is_active` only,
 * never hard-deleted (no `deleted_at` column exists at all), and no
 * invented positive/improvement classification (ReviewTag has no
 * sentiment field, and this suite never assumes one).
 */
class ReviewTagResourceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(ReviewPermissionSeeder::class);
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('manager');

        return $admin;
    }

    public function test_index_page_renders_for_a_permitted_manager(): void
    {
        $this->actingAs($this->admin())
            ->get(ReviewTagResource::getUrl('index'))
            ->assertOk();
    }

    public function test_index_page_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $this->actingAs($user)
            ->get(ReviewTagResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_a_manager_can_create_a_tag(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(CreateReviewTag::class)
            ->set('data.key', 'great_explanations')
            ->set('data.label', 'Great Explanations')
            ->set('data.applicable_modes', ['public_review'])
            ->set('data.is_active', true)
            ->set('data.sort_order', 1)
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('review_tags', [
            'key' => 'great_explanations',
            'label' => 'Great Explanations',
            'is_active' => true,
        ]);
    }

    public function test_the_key_is_required_and_unique(): void
    {
        $this->actingAs($this->admin());
        ReviewTag::factory()->create(['key' => 'patient']);

        Livewire::test(CreateReviewTag::class)
            ->set('data.key', 'patient')
            ->set('data.label', 'Patient Teacher')
            ->set('data.applicable_modes', ['public_review'])
            ->call('create')
            ->assertHasFormErrors(['key']);
    }

    public function test_a_manager_can_deactivate_a_tag_via_edit(): void
    {
        $tag = ReviewTag::factory()->create(['is_active' => true]);
        $this->actingAs($this->admin());

        Livewire::test(EditReviewTag::class, ['record' => $tag->getRouteKey()])
            ->set('data.is_active', false)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($tag->fresh()->is_active);
    }

    public function test_bulk_activate_and_deactivate_actions_work(): void
    {
        $tags = ReviewTag::factory()->count(2)->create(['is_active' => false]);
        $this->actingAs($this->admin());

        Livewire::test(ListReviewTags::class)
            ->callTableBulkAction('activate', $tags);

        $this->assertSame(2, ReviewTag::query()->where('is_active', true)->count());

        Livewire::test(ListReviewTags::class)
            ->callTableBulkAction('deactivate', $tags);

        $this->assertSame(0, ReviewTag::query()->where('is_active', true)->count());
    }

    public function test_no_delete_or_force_delete_action_exists_anywhere_in_the_resource(): void
    {
        foreach ([
            ReviewTagsTable::class,
            EditReviewTag::class,
            ListReviewTags::class,
        ] as $class) {
            $file = (new \ReflectionClass($class))->getFileName();
            $contents = file_get_contents($file);

            $this->assertStringNotContainsString('DeleteAction::make(', $contents);
            $this->assertStringNotContainsString('DeleteBulkAction::make(', $contents);
            $this->assertStringNotContainsString('ForceDeleteAction::make(', $contents);
            $this->assertStringNotContainsString('ForceDeleteBulkAction::make(', $contents);
        }
    }

    public function test_review_tag_model_has_no_soft_delete_column(): void
    {
        $this->assertFalse(Schema::hasColumn('review_tags', 'deleted_at'));
    }

    public function test_a_deactivated_tag_is_excluded_from_the_active_applicable_scope(): void
    {
        $active = ReviewTag::factory()->create(['is_active' => true, 'applicable_modes' => ['public_review']]);
        $inactive = ReviewTag::factory()->create(['is_active' => false, 'applicable_modes' => ['public_review']]);

        $available = ReviewTag::query()
            ->active()
            ->applicableTo(LessonReviewEligibilityMode::PublicReview)
            ->pluck('key');

        $this->assertTrue($available->contains($active->key));
        $this->assertFalse($available->contains($inactive->key));
    }
}
