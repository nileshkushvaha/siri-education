<?php

namespace Database\Seeders;

use App\Enums\Navigation\NavigationLayoutType;
use App\Enums\Navigation\NavigationLocation;
use App\Enums\Navigation\NavigationStatus;
use App\Models\NavigationMenu;
use Illuminate\Database\Seeder;

/**
 * Seeds one starter menu per navigation location.
 *
 * KEYED ON LOCATION, NOT SLUG. NavigationRepository::findByLocation()
 * resolves a location with an unordered ->first(), so two published
 * menus at the same location make the rendered menu non-deterministic.
 * Matching on slug alone let this seeder create a second, empty menu
 * beside an admin-built one whose slug happened to differ — silently
 * putting the live header or footer at risk. An existing menu at a
 * location is therefore left alone whatever it is called.
 */
class NavigationSeeder extends Seeder
{
    public function run(): void
    {
        $menus = [
            [
                'name' => 'Header Navigation',
                'slug' => 'header',
                'location' => NavigationLocation::Header->value,
                'layout_type' => NavigationLayoutType::Standard->value,
                'status' => NavigationStatus::Published->value,
                'description' => 'Primary navigation shown in the site header.',
            ],
            [
                'name' => 'Footer Navigation',
                'slug' => 'footer',
                'location' => NavigationLocation::Footer->value,
                'layout_type' => NavigationLayoutType::Standard->value,
                'status' => NavigationStatus::Published->value,
                'description' => 'Links displayed in the site footer.',
            ],
            [
                'name' => 'Mobile Navigation',
                'slug' => 'mobile',
                'location' => NavigationLocation::Mobile->value,
                'layout_type' => NavigationLayoutType::Accordion->value,
                'status' => NavigationStatus::Published->value,
                'description' => 'Navigation optimised for mobile devices.',
            ],
            [
                'name' => 'Sidebar Navigation',
                'slug' => 'sidebar',
                'location' => NavigationLocation::Sidebar->value,
                'layout_type' => NavigationLayoutType::Standard->value,
                'status' => NavigationStatus::Draft->value,
                'description' => 'Sidebar contextual navigation.',
            ],
            [
                'name' => 'User Menu',
                'slug' => 'user-menu',
                'location' => NavigationLocation::UserMenu->value,
                'layout_type' => NavigationLayoutType::Standard->value,
                'status' => NavigationStatus::Published->value,
                'description' => 'Dropdown menu shown in the authenticated user avatar.',
            ],
            [
                'name' => 'Footer Learning',
                'slug' => 'footer-learning',
                'location' => NavigationLocation::FooterLearning->value,
                'layout_type' => NavigationLayoutType::Standard->value,
                'status' => NavigationStatus::Published->value,
                'description' => 'Links displayed in the "Learning" column of the site footer.',
            ],
        ];

        foreach ($menus as $menu) {
            $exists = NavigationMenu::query()
                ->where(fn ($query) => $query
                    ->where('location', $menu['location'])
                    ->orWhere('slug', $menu['slug']))
                ->exists();

            if ($exists) {
                continue;
            }

            NavigationMenu::create($menu);
        }
    }
}
