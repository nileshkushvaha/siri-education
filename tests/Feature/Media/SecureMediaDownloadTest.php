<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Models\Conversation;
use App\Models\Lesson;
use App\Models\LessonTechnicalIssueReport;
use App\Models\Message;
use App\Models\Post;
use App\Models\SupportCase;
use App\Models\User;
use App\Models\UserEducation;
use App\Models\UserExperience;
use Database\Seeders\LessonPermissionSeeder;
use Database\Seeders\MessagingPermissionSeeder;
use Database\Seeders\SupportCasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SecureMediaDownloadController is the one
 * reusable, policy-authorized boundary for every privatized collection
 * (Message, SupportCase, LessonTechnicalIssueReport,
 * UserExperience::supporting_documents, UserEducation). Covers disk
 * enforcement, owner/admin/unrelated/guest access, foreign-collection
 * rejection, and sanitized downloads.
 */
final class SecureMediaDownloadTest extends TestCase
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
    }

    private function image(string $name = 'evidence.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name);
    }

    private function activeUser(): User
    {
        return User::factory()->create(['status' => User::STATUS_ACTIVE]);
    }

    private function manager(): User
    {
        $manager = $this->activeUser();
        $manager->assignRole('manager');

        return $manager;
    }

    // ── Message::attachment ───────────────────────────────────────────

    public function test_message_attachment_lands_on_the_private_local_disk(): void
    {
        $student = $this->activeUser();
        $instructor = $this->activeUser();
        $conversation = Conversation::factory()->between($student, $instructor)->create();
        $message = Message::factory()->create(['conversation_id' => $conversation->id, 'sender_id' => $student->id]);
        $message->addMedia($this->image())->toMediaCollection('attachment');

        $media = $message->fresh()->getFirstMedia('attachment');

        $this->assertSame('local', $media->disk);
    }

    public function test_message_attachment_is_downloadable_by_both_conversation_participants(): void
    {
        $student = $this->activeUser();
        $instructor = $this->activeUser();
        $conversation = Conversation::factory()->between($student, $instructor)->create();
        $message = Message::factory()->create(['conversation_id' => $conversation->id, 'sender_id' => $student->id]);
        $message->addMedia($this->image())->toMediaCollection('attachment');
        $media = $message->fresh()->getFirstMedia('attachment');

        $this->actingAs($student)->get(route('dashboard.media.download', $media))->assertOk();
        $this->actingAs($instructor)->get(route('dashboard.media.download', $media))->assertOk();
    }

    public function test_message_attachment_is_denied_to_an_unrelated_user(): void
    {
        $student = $this->activeUser();
        $instructor = $this->activeUser();
        $conversation = Conversation::factory()->between($student, $instructor)->create();
        $message = Message::factory()->create(['conversation_id' => $conversation->id, 'sender_id' => $student->id]);
        $message->addMedia($this->image())->toMediaCollection('attachment');
        $media = $message->fresh()->getFirstMedia('attachment');

        $stranger = $this->activeUser();
        $this->actingAs($stranger)->get(route('dashboard.media.download', $media))->assertForbidden();
    }

    public function test_message_attachment_is_denied_to_a_guest(): void
    {
        $student = $this->activeUser();
        $instructor = $this->activeUser();
        $conversation = Conversation::factory()->between($student, $instructor)->create();
        $message = Message::factory()->create(['conversation_id' => $conversation->id, 'sender_id' => $student->id]);
        $message->addMedia($this->image())->toMediaCollection('attachment');
        $media = $message->fresh()->getFirstMedia('attachment');

        $this->get(route('dashboard.media.download', $media))->assertRedirect(route('auth.login'));
    }

    public function test_message_attachment_is_downloadable_by_a_permission_holding_manager(): void
    {
        $this->seed(MessagingPermissionSeeder::class);
        $student = $this->activeUser();
        $instructor = $this->activeUser();
        $conversation = Conversation::factory()->between($student, $instructor)->create();
        $message = Message::factory()->create(['conversation_id' => $conversation->id, 'sender_id' => $student->id]);
        $message->addMedia($this->image())->toMediaCollection('attachment');
        $media = $message->fresh()->getFirstMedia('attachment');

        $this->actingAs($this->manager())->get(route('admin.media.download', $media))->assertOk();
    }

    public function test_message_preview_query_param_serves_the_generated_conversion_and_still_requires_authorization(): void
    {
        $student = $this->activeUser();
        $instructor = $this->activeUser();
        $conversation = Conversation::factory()->between($student, $instructor)->create();
        $message = Message::factory()->create(['conversation_id' => $conversation->id, 'sender_id' => $student->id]);
        $message->addMedia($this->image())->toMediaCollection('attachment');
        $media = $message->fresh()->getFirstMedia('attachment');
        $this->assertTrue($media->hasGeneratedConversion('preview'));

        $stranger = $this->activeUser();
        $this->actingAs($stranger)
            ->get(route('dashboard.media.download', $media).'?preview=1')
            ->assertForbidden();

        $this->actingAs($student)
            ->get(route('dashboard.media.download', $media).'?preview=1')
            ->assertOk();
    }

    // ── SupportCase::evidence ─────────────────────────────────────────

    public function test_support_case_evidence_lands_on_the_private_local_disk_and_is_owner_downloadable(): void
    {
        $student = $this->activeUser();
        $case = SupportCase::factory()->create(['student_id' => $student->id, 'created_by' => $student->id]);
        $case->addMedia($this->image())->toMediaCollection('evidence');

        $media = $case->fresh()->getFirstMedia('evidence');
        $this->assertSame('local', $media->disk);

        $this->actingAs($student)->get(route('dashboard.media.download', $media))->assertOk();
    }

    public function test_support_case_evidence_is_denied_to_an_unrelated_user_but_allowed_for_a_permission_holder(): void
    {
        $this->seed(SupportCasePermissionSeeder::class);
        $student = $this->activeUser();
        $case = SupportCase::factory()->create(['student_id' => $student->id, 'created_by' => $student->id]);
        $case->addMedia($this->image())->toMediaCollection('evidence');
        $media = $case->fresh()->getFirstMedia('evidence');

        $stranger = $this->activeUser();
        $this->actingAs($stranger)->get(route('dashboard.media.download', $media))->assertForbidden();

        $this->actingAs($this->manager())->get(route('admin.media.download', $media))->assertOk();
    }

    // ── LessonTechnicalIssueReport::evidence ──────────────────────────

    public function test_technical_issue_evidence_lands_on_the_private_disk_and_is_reporter_downloadable(): void
    {
        $student = $this->activeUser();
        $instructor = $this->activeUser();
        $lesson = Lesson::factory()->create(['student_id' => $student->id, 'instructor_id' => $instructor->id]);
        $report = LessonTechnicalIssueReport::create([
            'lesson_id' => $lesson->id,
            'reported_by' => $student->id,
            'reporter' => 'student',
            'category' => 'other',
            'description' => 'Audio failed',
            'occurred_at' => now(),
            'status' => 'pending',
            'submitted_at' => now(),
        ]);
        $report->addMedia($this->image())->toMediaCollection('evidence');

        $media = $report->fresh()->getFirstMedia('evidence');
        $this->assertSame('local', $media->disk);

        $this->actingAs($student)->get(route('dashboard.media.download', $media))->assertOk();
    }

    public function test_technical_issue_evidence_denies_the_other_lesson_participant_but_allows_a_reviewer(): void
    {
        $this->seed(LessonPermissionSeeder::class);
        $student = $this->activeUser();
        $instructor = $this->activeUser();
        $lesson = Lesson::factory()->create(['student_id' => $student->id, 'instructor_id' => $instructor->id]);
        $report = LessonTechnicalIssueReport::create([
            'lesson_id' => $lesson->id,
            'reported_by' => $student->id,
            'reporter' => 'student',
            'category' => 'other',
            'description' => 'Audio failed',
            'occurred_at' => now(),
            'status' => 'pending',
            'submitted_at' => now(),
        ]);
        $report->addMedia($this->image())->toMediaCollection('evidence');
        $media = $report->fresh()->getFirstMedia('evidence');

        // Deliberate: the OTHER participant (the instructor the report may
        // reflect on) is not automatically granted access — see policy docblock.
        $this->actingAs($instructor)->get(route('dashboard.media.download', $media))->assertForbidden();

        $this->actingAs($this->manager())->get(route('admin.media.download', $media))->assertOk();
    }

    // ── UserExperience::supporting_documents ──────────────────────────

    public function test_experience_supporting_document_lands_on_the_private_disk_and_is_owner_downloadable(): void
    {
        $user = $this->activeUser();
        $experience = UserExperience::factory()->create(['user_id' => $user->id]);
        $experience->addMedia($this->image())->toMediaCollection('supporting_documents');

        $media = $experience->fresh()->getFirstMedia('supporting_documents');
        $this->assertSame('local', $media->disk);

        $this->actingAs($user)->get(route('dashboard.media.download', $media))->assertOk();
    }

    public function test_experience_supporting_document_is_denied_to_an_unrelated_user_but_allowed_for_a_permission_holder(): void
    {
        Permission::firstOrCreate(['name' => 'Update:User', 'guard_name' => 'web']);
        $user = $this->activeUser();
        $experience = UserExperience::factory()->create(['user_id' => $user->id]);
        $experience->addMedia($this->image())->toMediaCollection('supporting_documents');
        $media = $experience->fresh()->getFirstMedia('supporting_documents');

        $stranger = $this->activeUser();
        $this->actingAs($stranger)->get(route('dashboard.media.download', $media))->assertForbidden();

        $admin = $this->activeUser();
        $admin->givePermissionTo('Update:User');
        $this->actingAs($admin)->get(route('dashboard.media.download', $media))->assertOk();
    }

    // ── UserEducation::certificate/transcript/degree_document ────────

    public function test_education_documents_land_on_the_private_disk_and_are_owner_downloadable(): void
    {
        $user = $this->activeUser();
        $education = UserEducation::factory()->create(['user_id' => $user->id]);
        $education->addMedia($this->image())->toMediaCollection('certificate');
        $education->addMedia($this->image('transcript.jpg'))->toMediaCollection('transcript');

        $certificate = $education->fresh()->getFirstMedia('certificate');
        $transcript = $education->fresh()->getFirstMedia('transcript');

        $this->assertSame('local', $certificate->disk);
        $this->assertSame('local', $transcript->disk);

        $this->actingAs($user)->get(route('dashboard.media.download', $certificate))->assertOk();
        $this->actingAs($user)->get(route('dashboard.media.download', $transcript))->assertOk();
    }

    // ── Foreign collection / cross-user rejection ─────────────────────

    public function test_a_collection_not_in_the_private_registry_is_rejected_even_for_an_authenticated_user(): void
    {
        $post = Post::factory()->create();
        $post->addMedia($this->image())->toMediaCollection('featured-image');
        $media = $post->fresh()->getFirstMedia('featured-image');

        $this->actingAs($this->activeUser())
            ->get(route('dashboard.media.download', $media))
            ->assertForbidden();
    }

    public function test_a_stranger_cannot_use_another_owners_education_media_id(): void
    {
        $ownerA = $this->activeUser();
        $educationA = UserEducation::factory()->create(['user_id' => $ownerA->id]);
        $educationA->addMedia($this->image())->toMediaCollection('certificate');
        $mediaA = $educationA->fresh()->getFirstMedia('certificate');

        $ownerB = $this->activeUser();

        $this->actingAs($ownerB)->get(route('dashboard.media.download', $mediaA))->assertForbidden();
    }

    // ── Sanitized downloads / no public exposure ──────────────────────

    public function test_download_response_has_a_sanitized_filename_and_no_raw_storage_path(): void
    {
        $student = $this->activeUser();
        $case = SupportCase::factory()->create(['student_id' => $student->id, 'created_by' => $student->id]);
        $case->addMedia($this->image('my-photo.jpg'))->toMediaCollection('evidence');
        $media = $case->fresh()->getFirstMedia('evidence');

        $response = $this->actingAs($student)->get(route('dashboard.media.download', $media));

        $response->assertOk();
        $disposition = $response->headers->get('content-disposition');
        $this->assertStringContainsString('my-photo.jpg', $disposition);
        $this->assertStringNotContainsString((string) $media->id, $disposition);
        $this->assertStringNotContainsString('storage/app', $disposition ?? '');
    }

    public function test_no_public_disk_url_is_ever_generated_for_a_privatized_collection(): void
    {
        $student = $this->activeUser();
        $instructor = $this->activeUser();
        $conversation = Conversation::factory()->between($student, $instructor)->create();
        $message = Message::factory()->create(['conversation_id' => $conversation->id, 'sender_id' => $student->id]);
        $message->addMedia($this->image())->toMediaCollection('attachment');
        $media = $message->fresh()->getFirstMedia('attachment');

        $this->assertFalse(Storage::disk('public')->exists($media->getPathRelativeToRoot()));
        $this->assertTrue(Storage::disk('local')->exists($media->getPathRelativeToRoot()));
    }
}
