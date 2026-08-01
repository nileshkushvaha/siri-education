<?php

declare(strict_types=1);

namespace App\Dashboard\DTOs;

use App\Reporting\DTOs\ReportDefinition;
use App\Reporting\Enums\ReportCategory;

/**
 * A launchpad entry derived from a registered {@see ReportDefinition}.
 * Built only from `ReportRegistryInterface::availableFor($user)`, so a
 * report the viewer may not open, or one marked `available: false`, can
 * never appear — the registry stays the single source of truth for what
 * exists and who may see it.
 */
final readonly class ReportLink
{
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public string $icon,
        public ReportCategory $category,
        public string $url,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'description' => $this->description,
            'icon' => $this->icon,
            'category' => $this->category->value,
            'url' => $this->url,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            key: (string) $data['key'],
            label: (string) $data['label'],
            description: (string) $data['description'],
            icon: (string) $data['icon'],
            category: ReportCategory::from((string) $data['category']),
            url: (string) $data['url'],
        );
    }

    public static function fromDefinition(ReportDefinition $definition, string $url): self
    {
        return new self(
            key: $definition->key,
            label: $definition->label,
            description: $definition->description,
            icon: $definition->category->icon(),
            category: $definition->category,
            url: $url,
        );
    }
}
