<?php

declare(strict_types=1);

namespace App\Notifications\Templates;

/**
 * The immutable, code-owned default subject/body for one
 * (template key, channel) pair, plus the allowlisted variable names
 * that may appear in that channel's text. For the 'database' channel,
 * "subject" means the notification's `title` field, not an email
 * subject line.
 */
final readonly class ChannelTemplateContent
{
    /** @param list<string> $variables */
    public function __construct(
        public NotificationTemplateChannel $channel,
        public string $defaultSubject,
        public string $defaultBody,
        public array $variables,
    ) {}
}
