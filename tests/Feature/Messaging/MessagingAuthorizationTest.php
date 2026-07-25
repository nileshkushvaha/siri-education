<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Messaging\Services\MessagingService;
use App\Models\Message;
use App\Models\User;
use Database\Seeders\MessagingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/** SRS §17.37: admin access to communication history must be permission-controlled. */
class MessagingAuthorizationTest extends TestCase
{
    use CreatesMessagingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureMessagingRoles();
        $this->seed(MessagingPermissionSeeder::class);
    }

    private function manager(): User
    {
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole('manager');

        return $manager;
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('dashboard.messages'))->assertRedirect(route('auth.login'));
    }

    public function test_a_non_participant_cannot_view_a_conversation(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->confirmedPaidBooking($student, $instructor);
        $conversation = app(MessagingService::class)->openOrFindConversation($student, $instructor, $student);
        $outsider = $this->student();

        $this->actingAs($outsider)
            ->get(route('dashboard.messages.show', $conversation))
            ->assertForbidden();
    }

    public function test_a_non_participant_cannot_reply(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->confirmedPaidBooking($student, $instructor);
        $conversation = app(MessagingService::class)->openOrFindConversation($student, $instructor, $student);
        $outsider = $this->student();

        $this->actingAs($outsider)
            ->post(route('dashboard.messages.reply', $conversation), ['body' => 'Not my conversation'])
            ->assertForbidden();
    }

    public function test_participant_can_view_their_own_conversation(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->confirmedPaidBooking($student, $instructor);
        $conversation = app(MessagingService::class)->openOrFindConversation($student, $instructor, $student);

        $this->actingAs($student)
            ->get(route('dashboard.messages.show', $conversation))
            ->assertOk();
    }

    public function test_participant_can_send_a_reply_via_the_dashboard(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->confirmedPaidBooking($student, $instructor);
        $conversation = app(MessagingService::class)->openOrFindConversation($student, $instructor, $student);

        $this->actingAs($student)
            ->post(route('dashboard.messages.reply', $conversation), ['body' => 'Hello!'])
            ->assertRedirect();

        $this->assertSame(1, Message::query()->where('conversation_id', $conversation->id)->count());
    }

    public function test_the_conversation_list_only_shows_the_users_own_conversations(): void
    {
        $studentA = $this->student();
        $studentB = $this->student();
        $instructor = $this->instructor();
        $this->confirmedPaidBooking($studentA, $instructor);
        $this->confirmedPaidBooking($studentB, $this->instructor());
        app(MessagingService::class)->openOrFindConversation($studentA, $instructor, $studentA);

        $response = $this->actingAs($studentB)->get(route('dashboard.messages'))->assertOk();
        $response->assertSee('No conversations yet');
    }

    public function test_authorized_manager_can_access_the_admin_conversation_resource(): void
    {
        $manager = $this->manager();
        $student = $this->student();
        $instructor = $this->instructor();
        $this->confirmedPaidBooking($student, $instructor);
        $conversation = app(MessagingService::class)->openOrFindConversation($student, $instructor, $student);

        $this->actingAs($manager)->get('/admin/conversations')->assertOk();
        $this->actingAs($manager)->get('/admin/conversations/'.$conversation->id)->assertOk();
    }

    public function test_unauthorized_user_cannot_access_the_admin_conversation_resource(): void
    {
        $this->actingAs($this->student())
            ->get('/admin/conversations')
            ->assertForbidden();
    }

    public function test_no_create_or_edit_route_exists_for_conversations(): void
    {
        $this->assertFalse(Route::has('filament.admin.resources.conversations.create'));
        $this->assertFalse(Route::has('filament.admin.resources.conversations.edit'));
    }
}
