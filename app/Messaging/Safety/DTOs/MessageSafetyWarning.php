<?php

declare(strict_types=1);

namespace App\Messaging\Safety\DTOs;

/**
 * The pre-send warning shown to a user who appears to be sharing
 * contact or payment details.
 *
 * USER EDUCATION, NOT MODERATION. It is produced entirely by
 * deterministic rules — no provider is involved, nothing is recorded,
 * and the user may always send anyway. The point is that someone about
 * to move off-platform learns what they are giving up at the moment it
 * matters, not that the platform stops them.
 */
final readonly class MessageSafetyWarning
{
    /** @param list<string> $reasons LeakageDetector flag keys */
    public function __construct(
        public array $reasons,
    ) {}

    public function isEmpty(): bool
    {
        return $this->reasons === [];
    }

    /** Plain, non-accusatory phrasing for each detected pattern. */
    public function summary(): string
    {
        $labels = [];

        foreach ($this->reasons as $reason) {
            $labels[] = match ($reason) {
                'email_address' => 'an email address',
                'phone_number' => 'a phone number',
                'external_link' => 'a link to another site',
                'off_platform_keyword' => 'another app or payment service',
                default => 'contact or payment details',
            };
        }

        $labels = array_values(array_unique($labels));

        return match (count($labels)) {
            0 => '',
            1 => $labels[0],
            2 => $labels[0].' and '.$labels[1],
            default => implode(', ', array_slice($labels, 0, -1)).' and '.end($labels),
        };
    }
}
