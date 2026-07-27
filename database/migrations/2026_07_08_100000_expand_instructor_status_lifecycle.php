<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The native DB enum(pending,approved,rejected,published) can only ever
 * hold those 4 values. The instructor lifecycle needs an
 * 11-state lifecycle (see App\Enums\InstructorStatus), so this widens
 * the column to a plain string — matching the string-backed-enum
 * convention used elsewhere (AcademicStatus, PageStatus, FaqStatus) —
 * and renames the 2 values that no longer match their old meaning:
 * pending -> submitted, published -> active. approved/rejected/null
 * are left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->string('instructor_status', 30)->nullable()->change();
        });

        DB::table('user_profiles')->where('instructor_status', 'pending')->update(['instructor_status' => 'submitted']);
        DB::table('user_profiles')->where('instructor_status', 'published')->update(['instructor_status' => 'active']);
    }

    public function down(): void
    {
        DB::table('user_profiles')->where('instructor_status', 'submitted')->update(['instructor_status' => 'pending']);
        DB::table('user_profiles')->where('instructor_status', 'active')->update(['instructor_status' => 'published']);

        // Any of the 7 new intermediate/end states have no equivalent in the
        // old 4-value set — collapse them to null rather than fail the rollback.
        DB::table('user_profiles')
            ->whereNotIn('instructor_status', ['pending', 'approved', 'rejected', 'published'])
            ->update(['instructor_status' => null]);

        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->enum('instructor_status', ['pending', 'approved', 'rejected', 'published'])->nullable()->change();
        });
    }
};
