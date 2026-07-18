<?php

namespace App\Enums;

enum PageContentWidth: string
{
    case Default = 'default';
    case FullWidth = 'full-width';
    case SidebarLeft = 'sidebar-left';
    case SidebarRight = 'sidebar-right';

    public static function resolve(?string $value): self
    {
        $width = self::tryFrom((string) $value);

        return $width?->isAvailable() === true ? $width : self::Default;
    }

    public static function options(): array
    {
        return [
            self::Default->value => '🖥️  Default (max-w-7xl)',
            self::FullWidth->value => '⬛  Full Width (edge to edge)',
            self::SidebarLeft->value => '◧  Sidebar Left (coming soon)',
            self::SidebarRight->value => '◨  Sidebar Right (coming soon)',
        ];
    }

    public function isAvailable(): bool
    {
        return match ($this) {
            self::Default, self::FullWidth => true,
            self::SidebarLeft, self::SidebarRight => false,
        };
    }

    public function contentContainerClasses(): string
    {
        return match ($this) {
            self::FullWidth => 'w-full',
            default => 'mx-auto max-w-7xl px-4 sm:px-6 lg:px-8',
        };
    }

    public function pageShellClasses(): string
    {
        return match ($this) {
            self::FullWidth => 'w-full [&>section>div.max-w-7xl]:max-w-none [&_.cms-section]:px-4 sm:[&_.cms-section]:px-6 lg:[&_.cms-section]:px-[max(2rem,calc((100vw-80rem)/2))]',
            default => 'w-full [&_.cms-section]:px-4 sm:[&_.cms-section]:px-6 lg:[&_.cms-section]:px-8',
        };
    }
}
