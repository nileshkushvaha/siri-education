<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Phase 3.1 — admin-configurable terminology for the level a
        // student picks under this Education System (CBSE "Class" /
        // US "Grade" / UK "Year"). Deliberately owned by EducationSystem,
        // not Country: one Country can host multiple Education Systems,
        // each with its own term. Nullable — a null value falls back to
        // the generic "Level"/"Levels" label at the presentation layer,
        // never hardcoded per-country PHP branching.
        Schema::table('education_systems', function (Blueprint $table): void {
            $table->string('level_term_singular', 30)->nullable()->after('display_order');
            $table->string('level_term_plural', 30)->nullable()->after('level_term_singular');
        });
    }

    public function down(): void
    {
        Schema::table('education_systems', function (Blueprint $table): void {
            $table->dropColumn(['level_term_singular', 'level_term_plural']);
        });
    }
};
