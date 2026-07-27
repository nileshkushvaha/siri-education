<?php

declare(strict_types=1);

namespace App\Notifications\Templates;

use InvalidArgumentException;

/**
 * One code-owned template registration:
 * stable key, human category/description for the admin UI, and one
 * ChannelTemplateContent per supported channel (its own default
 * subject/body/variables — never shared across channels).
 */
final readonly class NotificationTemplateDefinition
{
    /** @param array<string, ChannelTemplateContent> $content keyed by NotificationTemplateChannel::value */
    public function __construct(
        public NotificationTemplateKey $key,
        public string $category,
        public string $description,
        public array $content,
    ) {}

    /** @return list<NotificationTemplateChannel> */
    public function channels(): array
    {
        return array_map(
            fn (string $value): NotificationTemplateChannel => NotificationTemplateChannel::from($value),
            array_keys($this->content),
        );
    }

    public function supports(NotificationTemplateChannel $channel): bool
    {
        return array_key_exists($channel->value, $this->content);
    }

    public function contentFor(NotificationTemplateChannel $channel): ChannelTemplateContent
    {
        return $this->content[$channel->value]
            ?? throw new InvalidArgumentException(sprintf('Template "%s" does not support channel "%s".', $this->key->value, $channel->value));
    }
}
