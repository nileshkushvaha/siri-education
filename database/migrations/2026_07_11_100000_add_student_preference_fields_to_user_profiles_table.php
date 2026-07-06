<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            if (! Schema::hasColumn('user_profiles', 'student_academic_level_id')) {
                $table->uuid('student_academic_level_id')->nullable()->after('student_status');
                $table->foreign('student_academic_level_id')
                    ->references('id')
                    ->on('academic_levels')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('user_profiles', 'student_preferred_language_id')) {
                $table->foreignId('student_preferred_language_id')
                    ->nullable()
                    ->after('student_academic_level_id')
                    ->constrained('languages')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            if (Schema::hasColumn('user_profiles', 'student_preferred_language_id')) {
                $table->dropConstrainedForeignId('student_preferred_language_id');
            }

            if (Schema::hasColumn('user_profiles', 'student_academic_level_id')) {
                $table->dropForeign(['student_academic_level_id']);
                $table->dropColumn('student_academic_level_id');
            }
        });
    }
};
