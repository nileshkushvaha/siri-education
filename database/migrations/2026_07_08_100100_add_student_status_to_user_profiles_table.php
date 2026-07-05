<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Student-side lifecycle, kept separate from instructor_status and
 * User::status (see App\Enums\StudentStatus). Backfills 'registered'
 * for existing users holding the 'student' role so current accounts
 * aren't left null after this ships.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->string('student_status', 20)->nullable()->after('instructor_status');
        });

        DB::table('user_profiles')
            ->join('users', 'users.id', '=', 'user_profiles.user_id')
            ->join('model_has_roles', function ($join): void {
                $join->on('model_has_roles.model_id', '=', 'users.id')
                    ->where('model_has_roles.model_type', '=', 'App\\Models\\User');
            })
            ->join('roles', function ($join): void {
                $join->on('roles.id', '=', 'model_has_roles.role_id')
                    ->where('roles.name', '=', 'student');
            })
            ->update(['user_profiles.student_status' => 'registered']);
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->dropColumn('student_status');
        });
    }
};
