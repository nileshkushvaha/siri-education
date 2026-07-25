<?php

declare(strict_types=1);

namespace Tests\Feature\SupportCases;

use App\Exceptions\HistoricalRecordCannotBeDeletedException;
use App\Exceptions\ImmutableRecordCannotBeUpdatedException;
use App\Models\Activity;
use App\Models\SupportCase;
use App\Models\SupportCaseReply;
use App\Models\User;
use App\SupportCases\Enums\SupportCaseReplyVisibility;
use App\SupportCases\Exceptions\InvalidSupportCaseTransitionException;
use App\SupportCases\Services\SupportCaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SRS §25.19-25.20/§25.37/§25.41: requester replies, staff replies,
 * and the hard rule that internal notes are never visible to a
 * student or instructor. Replies are immutable history — no edit or
 * hard-delete path exists.
 */
class SupportCaseReplyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'Reply:SupportCase', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'AddInternalNote:SupportCase', 'guard_name' => 'web']);
    }

    private function student(): User
    {
        return User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
    }

    private function staff(): User
    {
        $staff = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $staff->assignRole('manager');
        $staff->givePermissionTo(['Reply:SupportCase', 'AddInternalNote:SupportCase']);

        return $staff;
    }

    public function test_requester_can_add_a_public_reply_via_the_dashboard(): void
    {
        $student = $this->student();
        $case = SupportCase::factory()->forStudent($student)->create();

        $this->actingAs($student)
            ->post(route('dashboard.support-cases.reply', $case), ['body' => 'Any update on this?'])
            ->assertRedirect();

        $reply = SupportCaseReply::query()->where('support_case_id', $case->id)->sole();
        $this->assertSame(SupportCaseReplyVisibility::RequesterVisible, $reply->visibility);
        $this->assertSame($student->id, $reply->author_id);
    }

    public function test_staff_can_add_a_public_reply_and_an_internal_note(): void
    {
        $case = SupportCase::factory()->create();
        $staff = $this->staff();
        $service = app(SupportCaseService::class);

        $publicReply = $service->addReply($case, $staff, 'We are looking into this.', SupportCaseReplyVisibility::RequesterVisible);
        $note = $service->addReply($case, $staff, 'Payment reference confirmed in gateway logs.', SupportCaseReplyVisibility::InternalNote);

        $this->assertSame(SupportCaseReplyVisibility::RequesterVisible, $publicReply->visibility);
        $this->assertSame(SupportCaseReplyVisibility::InternalNote, $note->visibility);
    }

    public function test_a_requester_without_permission_cannot_add_an_internal_note(): void
    {
        $student = $this->student();
        $case = SupportCase::factory()->forStudent($student)->create();

        $this->expectException(InvalidSupportCaseTransitionException::class);
        app(SupportCaseService::class)->addReply($case, $student, 'Sneaky internal note attempt.', SupportCaseReplyVisibility::InternalNote);
    }

    public function test_internal_notes_are_excluded_from_the_requester_visible_relation(): void
    {
        $student = $this->student();
        $case = SupportCase::factory()->forStudent($student)->create();
        $staff = $this->staff();
        $service = app(SupportCaseService::class);

        $service->addReply($case, $staff, 'Public update for the student.', SupportCaseReplyVisibility::RequesterVisible);
        $service->addReply($case, $staff, 'Internal-only investigation note.', SupportCaseReplyVisibility::InternalNote);

        $visible = $case->fresh()->requesterVisibleReplies()->pluck('body')->all();
        $this->assertContains('Public update for the student.', $visible);
        $this->assertNotContains('Internal-only investigation note.', $visible);
    }

    public function test_internal_note_never_appears_on_the_requester_facing_case_page(): void
    {
        $student = $this->student();
        $case = SupportCase::factory()->forStudent($student)->create();
        $staff = $this->staff();

        app(SupportCaseService::class)->addReply($case, $staff, 'CONFIDENTIAL_INVESTIGATION_NOTE_TEXT', SupportCaseReplyVisibility::InternalNote);
        app(SupportCaseService::class)->addReply($case, $staff, 'PUBLIC_REPLY_TEXT', SupportCaseReplyVisibility::RequesterVisible);

        $this->actingAs($student)
            ->get(route('dashboard.support-cases.show', $case))
            ->assertOk()
            ->assertSee('PUBLIC_REPLY_TEXT')
            ->assertDontSee('CONFIDENTIAL_INVESTIGATION_NOTE_TEXT');
    }

    public function test_a_reply_cannot_be_updated(): void
    {
        $case = SupportCase::factory()->create();
        $reply = SupportCaseReply::factory()->create(['support_case_id' => $case->id]);

        $this->expectException(ImmutableRecordCannotBeUpdatedException::class);
        $reply->forceFill(['body' => 'Tampered'])->save();
    }

    public function test_a_reply_cannot_be_hard_deleted(): void
    {
        $case = SupportCase::factory()->create();
        $reply = SupportCaseReply::factory()->create(['support_case_id' => $case->id]);

        $this->expectException(HistoricalRecordCannotBeDeletedException::class);
        $reply->delete();
    }

    public function test_a_support_case_cannot_be_hard_deleted(): void
    {
        $case = SupportCase::factory()->create();

        $this->expectException(HistoricalRecordCannotBeDeletedException::class);
        $case->delete();
    }

    public function test_reply_creation_is_audit_logged(): void
    {
        $case = SupportCase::factory()->create();
        $staff = $this->staff();

        app(SupportCaseService::class)->addReply($case, $staff, 'Investigating now.', SupportCaseReplyVisibility::RequesterVisible);

        $this->assertTrue(
            Activity::query()->where('log_name', 'support_cases')->where('event', 'public_reply_added')->exists()
        );
    }

    public function test_internal_note_creation_is_audit_logged_distinctly(): void
    {
        $case = SupportCase::factory()->create();
        $staff = $this->staff();

        app(SupportCaseService::class)->addReply($case, $staff, 'Internal only.', SupportCaseReplyVisibility::InternalNote);

        $this->assertTrue(
            Activity::query()->where('log_name', 'support_cases')->where('event', 'internal_note_added')->exists()
        );
    }
}
