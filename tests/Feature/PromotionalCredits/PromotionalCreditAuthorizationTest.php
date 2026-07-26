<?php

declare(strict_types=1);

namespace Tests\Feature\PromotionalCredits;

use App\PromotionalCredits\Services\PromotionalCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Feature\PromotionalCredits\Concerns\CreatesPromotionalCreditFixtures;
use Tests\TestCase;

/** Admin surfaces are permission-controlled; no create/edit/delete anywhere for issuances. */
class PromotionalCreditAuthorizationTest extends TestCase
{
    use CreatesPromotionalCreditFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensurePromotionalCreditRoles();
    }

    public function test_authorized_admin_can_access_the_campaign_resource(): void
    {
        $admin = $this->fullAdmin();

        $this->actingAs($admin)->get('/admin/promotional-credit-campaigns')->assertOk();
    }

    public function test_unauthorized_user_cannot_access_the_campaign_resource(): void
    {
        $this->actingAs($this->student())
            ->get('/admin/promotional-credit-campaigns')
            ->assertForbidden();
    }

    public function test_authorized_admin_can_access_the_issuance_resource(): void
    {
        $admin = $this->fullAdmin();

        $this->actingAs($admin)->get('/admin/promotional-credit-issuances')->assertOk();
    }

    public function test_unauthorized_user_cannot_access_the_issuance_resource(): void
    {
        $this->actingAs($this->student())
            ->get('/admin/promotional-credit-issuances')
            ->assertForbidden();
    }

    public function test_no_create_edit_or_delete_route_exists_for_issuances(): void
    {
        $this->assertFalse(Route::has('filament.admin.resources.promotional-credit-issuances.create'));
        $this->assertFalse(Route::has('filament.admin.resources.promotional-credit-issuances.edit'));
    }

    public function test_admin_can_view_a_single_issuance(): void
    {
        $admin = $this->fullAdmin();
        $student = $this->student();
        $issuance = app(PromotionalCreditService::class)->issueManualCredit($student, $admin, 15000, 'INR', 'Reason.', 'promo_credit:'.Str::uuid());

        $this->actingAs($admin)
            ->get('/admin/promotional-credit-issuances/'.$issuance->id)
            ->assertOk();
    }
}
