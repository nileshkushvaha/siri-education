<?php

namespace App\Enums\Navigation;

enum NavigationLocation: string
{
    case Header = 'header';
    case Footer = 'footer';
    case Mobile = 'mobile';
    case Sidebar = 'sidebar';
    case UserMenu = 'user_menu';
    /**
     * Renders the footer's "Learning" column. Replaced the former
     * AdminMenu location, which had no renderer, no seeded content and
     * no reader anywhere in the application — the admin panel is
     * Filament's own navigation, not a NavigationMenu.
     */
    case FooterLearning = 'footer_learning';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Header => 'Header',
            self::Footer => 'Footer',
            self::Mobile => 'Mobile',
            self::Sidebar => 'Sidebar',
            self::UserMenu => 'User Menu',
            self::FooterLearning => 'Footer Learning',
            self::Custom => 'Custom',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Header => 'info',
            self::Footer => 'gray',
            self::Mobile => 'warning',
            self::Sidebar => 'primary',
            self::UserMenu => 'success',
            self::FooterLearning => 'gray',
            self::Custom => 'purple',
        };
    }
}
