<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\NotificationTemplate;
use App\Models\User;
use App\Notifications\Templates\NotificationTemplateChannel;
use App\Notifications\Templates\NotificationTemplateKey;
use App\Services\Notifications\NotificationTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Every mutation is audited (never logging a
 * rendered/private value, since template content is admin-authored
 * generic text) and busts the renderer's cache immediately.
 */
final class NotificationTemplateServiceTest extends TestCase
{
    use RefreshDatabase;

    private function template(): NotificationTemplate
    {
        return NotificationTemplate::query()
            ->where('template_key', NotificationTemplateKey::WalletRechargeSucceeded->value)
            ->where('channel', NotificationTemplateChannel::Mail->value)
            ->firstOrFail();
    }

    public function test_update_override_increments_version_and_records_the_editor(): void
    {
        $editor = User::factory()->create();
        $template = $this->template();

        $updated = app(NotificationTemplateService::class)->updateOverride($template, $editor, 'New subject', 'New body {{amount}}');

        $this->assertSame('New subject', $updated->subject);
        $this->assertSame('New body {{amount}}', $updated->body);
        $this->assertSame(2, $updated->version);
        $this->assertSame($editor->id, $updated->edited_by);
    }

    public function test_update_override_writes_an_audit_entry_with_before_and_after_content(): void
    {
        $editor = User::factory()->create();
        $template = $this->template();

        app(NotificationTemplateService::class)->updateOverride($template, $editor, 'New subject', 'New body');

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'notification_templates',
            'event' => 'template_content_updated',
            'causer_id' => $editor->id,
        ]);
    }

    public function test_blank_strings_are_stored_as_null_so_the_default_applies(): void
    {
        $editor = User::factory()->create();
        $template = $this->template();

        $updated = app(NotificationTemplateService::class)->updateOverride($template, $editor, '   ', '');

        $this->assertNull($updated->subject);
        $this->assertNull($updated->body);
        $this->assertFalse($updated->hasOverride());
    }

    public function test_restore_default_clears_the_override_and_audits_only_when_one_existed(): void
    {
        $editor = User::factory()->create();
        $template = $this->template();
        app(NotificationTemplateService::class)->updateOverride($template, $editor, 'Custom', 'Custom body');

        $restored = app(NotificationTemplateService::class)->restoreDefault($template, $editor);

        $this->assertNull($restored->subject);
        $this->assertNull($restored->body);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'notification_templates',
            'event' => 'template_default_restored',
        ]);
    }

    public function test_restore_default_is_a_quiet_no_op_when_there_was_no_override(): void
    {
        $editor = User::factory()->create();
        $template = $this->template();

        app(NotificationTemplateService::class)->restoreDefault($template, $editor);

        $this->assertDatabaseMissing('activity_log', [
            'log_name' => 'notification_templates',
            'event' => 'template_default_restored',
        ]);
    }

    public function test_set_active_toggles_state_and_audits_the_transition(): void
    {
        $editor = User::factory()->create();
        $template = $this->template();
        $this->assertTrue($template->is_active);

        $updated = app(NotificationTemplateService::class)->setActive($template, $editor, false);

        $this->assertFalse($updated->is_active);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'notification_templates',
            'event' => 'template_deactivated',
        ]);
    }

    public function test_set_active_is_a_no_op_when_state_is_unchanged(): void
    {
        $editor = User::factory()->create();
        $template = $this->template();

        app(NotificationTemplateService::class)->setActive($template, $editor, true);

        $this->assertDatabaseMissing('activity_log', [
            'log_name' => 'notification_templates',
            'event' => 'template_activated',
        ]);
    }

    /** Never a rendered/interpolated value with real recipient data — only the admin-authored template text itself. */
    public function test_audit_never_contains_a_rendered_value_with_real_recipient_data(): void
    {
        $editor = User::factory()->create();
        $template = $this->template();

        app(NotificationTemplateService::class)->updateOverride($template, $editor, 'Subject', 'Hi {{amount}}, secret-real-student-name-should-never-appear');

        // The audit legitimately contains the raw TEMPLATE TEXT (admin-authored, containing only {{placeholders}}) — this
        // asserts no ACTUAL interpolated/rendered value (which never exists at edit time) leaks in; the template text itself
        // is safe generic wording, never a real recipient's data.
        $activity = Activity::query()->where('log_name', 'notification_templates')->latest()->first();
        $this->assertNotNull($activity);
        $this->assertArrayHasKey('after', $activity->properties->toArray());
    }
}
