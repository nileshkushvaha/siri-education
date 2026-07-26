<?php

declare(strict_types=1);

namespace App\Notifications\Templates;

/**
 * GAP-039 — the renderer's output. `subject` is an email subject line
 * for the 'mail' channel or a notification title for 'database'.
 * `lines` are already-escaped, already-interpolated, non-empty body
 * lines in order — a notification calls ->line() (or joins for
 * 'message') for each one and never touches raw template text itself.
 */
final readonly class RenderedNotificationTemplate
{
    /** @param list<string> $lines */
    public function __construct(
        public string $subject,
        public array $lines,
    ) {}

    public function message(): string
    {
        return implode(' ', $this->lines);
    }
}
