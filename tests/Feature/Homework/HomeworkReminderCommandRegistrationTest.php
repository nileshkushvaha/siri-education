<?php

declare(strict_types=1);

namespace Tests\Feature\Homework;

use App\Console\Commands\Homework\SendHomeworkDueReminders;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Confirms homework:send-due-reminders is
 * actually registered on the scheduler with overlap/single-server
 * protection, matching every other idempotent sweep in routes/console.php.
 */
final class HomeworkReminderCommandRegistrationTest extends TestCase
{
    public function test_command_is_scheduled_with_overlap_and_single_server_protection(): void
    {
        $schedule = app(Schedule::class);

        $event = collect($schedule->events())
            ->first(fn ($event) => str_contains($event->command ?? '', 'homework:send-due-reminders'));

        $this->assertNotNull($event, 'homework:send-due-reminders is not registered on the scheduler.');
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    public function test_command_signature_matches_the_documented_name(): void
    {
        $this->assertSame('homework:send-due-reminders', (new SendHomeworkDueReminders)->getName());
    }
}
