<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Maps existing SubjectTopic rows into a Curriculum Module —
        // deliberately not a second topic-master table (see
        // docs/architecture/phase-12.5-academic-taxonomy-subject-topics.md).
        // The topic must belong to the same subject as the curriculum
        // (enforced in CurriculumService, not just here).
        Schema::create('curriculum_module_topics', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('curriculum_module_id');
            $table->uuid('subject_topic_id');
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('curriculum_module_id')->references('id')->on('curriculum_modules')->cascadeOnDelete();
            $table->foreign('subject_topic_id')->references('id')->on('subject_topics')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            // A topic can only be assigned once to a given module.
            $table->unique(['curriculum_module_id', 'subject_topic_id'], 'curriculum_module_topics_module_topic_unique');
            $table->index(['curriculum_module_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curriculum_module_topics');
    }
};
