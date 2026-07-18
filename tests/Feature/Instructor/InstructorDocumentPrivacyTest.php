<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor;

use App\Enums\InstructorStatus;
use App\Filament\Resources\InstructorOnboarding\Pages\EditInstructorOnboarding;
use App\Models\Activity;
use App\Models\User;
use App\Services\Instructor\InstructorOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class InstructorDocumentPrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'ViewAny:User', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'View:User', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'Update:User', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => InstructorOnboardingService::REVIEW_PERMISSION, 'guard_name' => 'web']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    // ── Authorization (Filament section visibility) ────────────────

    public function test_reviewer_can_see_the_verification_documents_section(): void
    {
        $reviewer = $this->genericAdmin();
        $reviewer->givePermissionTo(InstructorOnboardingService::REVIEW_PERMISSION);
        $instructor = $this->instructorWithDocument();

        $this->actingAs($reviewer);

        Livewire::test(EditInstructorOnboarding::class, ['record' => $instructor->getRouteKey()])
            ->assertSee('Verification Evidence');
    }

    public function test_normal_admin_without_review_permission_cannot_see_kyc_section(): void
    {
        $admin = $this->genericAdmin();
        $admin->givePermissionTo('Update:User');
        // Deliberately no REVIEW_PERMISSION.
        $instructor = $this->instructorWithDocument();

        $this->actingAs($admin);

        Livewire::test(EditInstructorOnboarding::class, ['record' => $instructor->getRouteKey()])
            ->assertDontSee('Verification Evidence')
            ->assertDontSee('Government ID');
    }

    public function test_student_cannot_reach_the_download_route(): void
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        $instructor = $this->instructorWithDocument();
        $media = $instructor->profile->getFirstMedia('government_id');

        $this->actingAs($student)
            ->get(route('admin.instructor-documents.download', $media))
            ->assertForbidden();
    }

    public function test_instructor_cannot_download_their_own_kyc_file_without_review_permission(): void
    {
        $instructor = $this->instructorWithDocument();
        $media = $instructor->profile->getFirstMedia('government_id');

        $this->actingAs($instructor)
            ->get(route('admin.instructor-documents.download', $media))
            ->assertForbidden();
    }

    // ── Storage ──────────────────────────────────────────────────

    public function test_kyc_collections_are_stored_on_the_local_disk(): void
    {
        $instructor = $this->instructorWithDocument();
        $media = $instructor->profile->getFirstMedia('government_id');

        $this->assertSame('local', $media->disk);
    }

    public function test_avatar_collection_remains_on_the_public_disk_and_is_unaffected(): void
    {
        $instructor = $this->instructorWithDocument();
        $instructor->profile->addMedia(UploadedFile::fake()->image('avatar.jpg'))->toMediaCollection('avatar');
        $media = $instructor->profile->fresh()->getFirstMedia('avatar');

        $this->assertSame('public', $media->disk);
    }

    // ── Download ─────────────────────────────────────────────────

    public function test_reviewer_receives_a_200_download_response(): void
    {
        $reviewer = $this->genericAdmin();
        $reviewer->givePermissionTo(InstructorOnboardingService::REVIEW_PERMISSION);
        $instructor = $this->instructorWithDocument();
        $media = $instructor->profile->getFirstMedia('government_id');

        $this->actingAs($reviewer)
            ->get(route('admin.instructor-documents.download', $media))
            ->assertOk();
    }

    public function test_unauthorized_admin_receives_403(): void
    {
        $admin = $this->genericAdmin();
        $admin->givePermissionTo('View:User');
        $instructor = $this->instructorWithDocument();
        $media = $instructor->profile->getFirstMedia('government_id');

        $this->actingAs($admin)
            ->get(route('admin.instructor-documents.download', $media))
            ->assertForbidden();
    }

    public function test_missing_media_returns_404(): void
    {
        $reviewer = $this->genericAdmin();
        $reviewer->givePermissionTo(InstructorOnboardingService::REVIEW_PERMISSION);

        $this->actingAs($reviewer)
            ->get(route('admin.instructor-documents.download', ['media' => 999999]))
            ->assertNotFound();
    }

    public function test_wrong_collection_media_is_rejected_even_for_a_reviewer(): void
    {
        $reviewer = $this->genericAdmin();
        $reviewer->givePermissionTo(InstructorOnboardingService::REVIEW_PERMISSION);
        $instructor = $this->instructorWithDocument();
        $instructor->profile->addMedia(UploadedFile::fake()->image('avatar.jpg'))->toMediaCollection('avatar');
        $avatarMedia = $instructor->profile->fresh()->getFirstMedia('avatar');

        $this->actingAs($reviewer)
            ->get(route('admin.instructor-documents.download', $avatarMedia))
            ->assertForbidden();
    }

    // ── Audit ────────────────────────────────────────────────────

    public function test_downloading_a_document_creates_an_audit_entry(): void
    {
        $reviewer = $this->genericAdmin();
        $reviewer->givePermissionTo(InstructorOnboardingService::REVIEW_PERMISSION);
        $instructor = $this->instructorWithDocument();
        $media = $instructor->profile->getFirstMedia('government_id');

        $this->actingAs($reviewer)
            ->get(route('admin.instructor-documents.download', $media))
            ->assertOk();

        $activity = Activity::query()
            ->where('log_name', 'instructor')
            ->where('event', 'instructor_document_downloaded')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame('government_id', $activity->properties['collection'] ?? null);
        $this->assertSame($media->id, $activity->properties['media_id'] ?? null);
        $this->assertArrayNotHasKey('filename', $activity->properties->toArray());
        $this->assertArrayNotHasKey('path', $activity->properties->toArray());
    }

    public function test_viewing_the_kyc_section_creates_an_audit_entry(): void
    {
        $reviewer = $this->genericAdmin();
        $reviewer->givePermissionTo(InstructorOnboardingService::REVIEW_PERMISSION);
        $instructor = $this->instructorWithDocument();

        $this->actingAs($reviewer);
        Livewire::test(EditInstructorOnboarding::class, ['record' => $instructor->getRouteKey()]);

        $this->assertSame(
            1,
            Activity::query()->where('log_name', 'instructor')->where('event', 'instructor_document_viewed')->count(),
        );
    }

    private function genericAdmin(): User
    {
        // Holds Filament panel access (manager role) and baseline
        // resource-page access (ViewAny/View/Update:User — required just
        // to reach the EditUser page at all, per Filament's
        // CanAuthorizeResourceAccess) but no KYC review permission by
        // default — each test grants that separately where relevant.
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('manager');
        $admin->givePermissionTo(['ViewAny:User', 'View:User', 'Update:User']);

        return $admin;
    }

    private function instructorWithDocument(): User
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE, 'email_verified_at' => now()]);
        $instructor->assignRole('instructor');
        $instructor->profile()->update(['instructor_status' => InstructorStatus::Submitted]);
        $instructor->refresh();

        $instructor->profile->addMedia(UploadedFile::fake()->image('government_id.jpg'))
            ->toMediaCollection('government_id');

        return $instructor->fresh();
    }
}
