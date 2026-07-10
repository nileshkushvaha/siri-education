<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // UK/US-first education-system support without a full
        // education_systems module (deliberately deferred — see
        // docs/architecture/phase-12.5-academic-taxonomy-subject-topics.md):
        // a level may belong to one country's system (UK "Year 10",
        // US "Grade 9") or stay global (null). min_grade/max_grade keep
        // bridging every level to the universal 1-12 grade ints the
        // booking engine matches on, so India (Class 1-12) later is
        // just more admin-entered rows.
        Schema::table('academic_levels', function (Blueprint $table): void {
            $table->unsignedBigInteger('country_id')->nullable()->after('max_grade');

            $table->foreign('country_id')->references('id')->on('countries')->nullOnDelete();
            $table->index('country_id');
        });
    }

    public function down(): void
    {
        Schema::table('academic_levels', function (Blueprint $table): void {
            $table->dropForeign(['country_id']);
            $table->dropIndex(['country_id']);
            $table->dropColumn('country_id');
        });
    }
};
