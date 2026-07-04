<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Livewire\Frontend\Student\NotificationsPanel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NotificationsPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $this->student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->student->assignRole('student');
    }

    public function test_page_renders_the_livewire_component(): void
    {
        $this->actingAs($this->student)
            ->get(route('dashboard.notifications'))
            ->assertOk()
            ->assertSeeLivewire(NotificationsPanel::class);
    }

    public function test_mark_read_marks_one_notification_as_read(): void
    {
        $notification = $this->createNotification($this->student, 'Unread', 'body');

        Livewire::actingAs($this->student)
            ->test(NotificationsPanel::class)
            ->call('markRead', $notification->id);

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_mark_all_read_clears_all_unread(): void
    {
        $this->createNotification($this->student, 'Alert 1', 'body');
        $this->createNotification($this->student, 'Alert 2', 'body');

        Livewire::actingAs($this->student)
            ->test(NotificationsPanel::class)
            ->call('markAllRead');

        $this->assertSame(0, $this->student->fresh()->unreadNotifications()->count());
    }

    public function test_cannot_mark_another_users_notification_as_read(): void
    {
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $notification = $this->createNotification($other, 'Private', 'body');

        Livewire::actingAs($this->student)
            ->test(NotificationsPanel::class)
            ->call('markRead', $notification->id);

        $this->assertNull($notification->fresh()->read_at);
    }

    private function createNotification(User $user, string $title, string $body): DatabaseNotification
    {
        return DatabaseNotification::create([
            'id' => Str::uuid()->toString(),
            'type' => 'App\\Notifications\\TestNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => ['title' => $title, 'message' => $body],
            'read_at' => null,
        ]);
    }
}
