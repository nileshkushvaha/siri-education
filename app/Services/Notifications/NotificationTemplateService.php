<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\NotificationTemplate;
use App\Models\User;
use App\Notifications\Templates\NotificationTemplateRenderer;
use App\Services\AuditTrailService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of NotificationTemplate
 * rows. Every mutation is audited via AuditTrailService (never
 * activity() directly, per CLAUDE.md) and busts the renderer's cache
 * for that exact (key, channel) so the next render sees the change
 * immediately. Template content is admin-authored generic text (never
 * a real recipient's data), so before/after subject/body are safe to
 * audit in full — nothing here is a "rendered" (interpolated) value.
 */
final class NotificationTemplateService
{
    public function __construct(
        private readonly AuditTrailService $audit,
    ) {}

    public function updateOverride(NotificationTemplate $template, User $editor, ?string $subject, ?string $body): NotificationTemplate
    {
        $before = ['subject' => $template->subject, 'body' => $template->body, 'version' => $template->version];

        return DB::transaction(function () use ($template, $editor, $subject, $body, $before): NotificationTemplate {
            $template->fill([
                'subject' => $subject !== null && trim($subject) !== '' ? $subject : null,
                'body' => $body !== null && trim($body) !== '' ? $body : null,
                'version' => $template->version + 1,
                'edited_by' => $editor->id,
            ])->save();

            $this->audit->logUser(
                $editor,
                'notification_templates',
                'template_content_updated',
                sprintf('Notification template "%s" (%s) content updated.', $template->template_key->value, $template->channel->value),
                $template,
                ['before' => $before, 'after' => ['subject' => $template->subject, 'body' => $template->body, 'version' => $template->version]],
            );

            $this->bustCache($template);

            return $template;
        });
    }

    public function restoreDefault(NotificationTemplate $template, User $editor): NotificationTemplate
    {
        return DB::transaction(function () use ($template, $editor): NotificationTemplate {
            $hadOverride = $template->subject !== null || $template->body !== null;

            $template->fill([
                'subject' => null,
                'body' => null,
                'version' => $template->version + 1,
                'edited_by' => $editor->id,
            ])->save();

            if ($hadOverride) {
                $this->audit->logUser(
                    $editor,
                    'notification_templates',
                    'template_default_restored',
                    sprintf('Notification template "%s" (%s) restored to its code-owned default.', $template->template_key->value, $template->channel->value),
                    $template,
                    [],
                );
            }

            $this->bustCache($template);

            return $template;
        });
    }

    public function setActive(NotificationTemplate $template, User $editor, bool $active): NotificationTemplate
    {
        return DB::transaction(function () use ($template, $editor, $active): NotificationTemplate {
            if ($template->is_active === $active) {
                return $template;
            }

            $template->fill(['is_active' => $active, 'edited_by' => $editor->id])->save();

            $this->audit->logUser(
                $editor,
                'notification_templates',
                $active ? 'template_activated' : 'template_deactivated',
                sprintf('Notification template "%s" (%s) %s.', $template->template_key->value, $template->channel->value, $active ? 'activated' : 'deactivated'),
                $template,
                [],
            );

            $this->bustCache($template);

            return $template;
        });
    }

    private function bustCache(NotificationTemplate $template): void
    {
        Cache::forget(NotificationTemplateRenderer::cacheKey(
            $template->template_key,
            $template->channel,
        ));
    }
}
