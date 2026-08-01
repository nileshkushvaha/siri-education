<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `users` carried no index on `created_at`, yet two dashboard figures
 * depend on it directly:
 *
 *  - the "New students" KPI, from
 *    `StudentEngagementRepository`'s `users.created_at BETWEEN ...`
 *    period scoping;
 *  - the daily registration trend, which additionally groups by
 *    `DATE(CONVERT_TZ(users.created_at, ...))` over the same range.
 *
 * Both previously required a full table scan on every dashboard load.
 * The range predicate is the selective part, so a single-column index
 * is what the planner needs; the grouping expression is not
 * index-resolvable in either shape and is unaffected.
 *
 * This is the only index this work adds. In particular no
 * `login_histories.logged_in_at` index is added: the "Today's logins"
 * statistic that would have justified it has been removed from the
 * dashboard rather than kept alive to justify an index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->index('created_at', 'users_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_created_at_index');
        });
    }
};
