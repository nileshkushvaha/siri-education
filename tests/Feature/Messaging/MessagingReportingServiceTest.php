<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Messaging\Enums\MessageReportReason;
use App\Messaging\Services\MessagingReportingService;
use App\Messaging\Services\MessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/** SRS §17.44 minimal messaging reporting — bounded aggregate counts. */
class MessagingReportingServiceTest extends TestCase
{
    use CreatesMessagingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureMessagingRoles();
    }

    public function test_reporting_counts_reflect_actual_activity(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->confirmedPaidBooking($student, $instructor);
        $service = app(MessagingService::class);
        $conversation = $service->openOrFindConversation($student, $instructor, $student);
        $service->send($conversation, $student, 'Hello');
        $message = $service->send($conversation, $instructor, 'Hi back');
        $service->reportMessage($message, $student, MessageReportReason::Spam);
        $admin = $this->manager();
        $admin->givePermissionTo(Permission::firstOrCreate(['name' => 'Restrict:Messaging', 'guard_name' => 'web']));
        $service->applyRestriction($instructor, $admin, 'Testing restriction counts.');

        $reporting = app(MessagingReportingService::class);

        $this->assertSame(1, $reporting->totalConversations());
        $this->assertSame(2, $reporting->totalMessages());
        $this->assertSame(1, $reporting->totalReportCount());
        $this->assertSame(1, $reporting->pendingReportCount());
        $this->assertSame(1, $reporting->activeRestrictionCount());
    }
}
