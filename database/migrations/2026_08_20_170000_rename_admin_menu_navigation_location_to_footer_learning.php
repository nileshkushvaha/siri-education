<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repoints the retired `admin_menu` navigation location at the footer's
 * "Learning" column.
 *
 * The old location was dead weight: nothing rendered it, nothing read it,
 * and the admin panel's own navigation comes from Filament rather than a
 * NavigationMenu row. Rather than delete the location and orphan any menu
 * an admin had already built under it, the value is renamed in place so
 * existing menus and their items survive and simply start rendering
 * somewhere visible.
 *
 * The name/slug rewrite is conditional on the seeded defaults, so a menu
 * an admin has already renamed keeps its own title.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('navigations')
            ->where('location', 'admin_menu')
            ->update(['location' => 'footer_learning']);

        DB::table('navigations')
            ->where('location', 'footer_learning')
            ->where('name', 'Admin Menu')
            ->where('slug', 'admin-menu')
            ->update([
                'name' => 'Footer Learning',
                'slug' => 'footer-learning',
                'description' => 'Links displayed in the "Learning" column of the site footer.',
            ]);
    }

    public function down(): void
    {
        DB::table('navigations')
            ->where('location', 'footer_learning')
            ->where('name', 'Footer Learning')
            ->where('slug', 'footer-learning')
            ->update([
                'name' => 'Admin Menu',
                'slug' => 'admin-menu',
                'description' => 'Administrative navigation visible to admin users.',
            ]);

        DB::table('navigations')
            ->where('location', 'footer_learning')
            ->update(['location' => 'admin_menu']);
    }
};
