<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Parts of a subject an instructor can teach independently
        // (Mathematics → Algebra/Geometry/…). A shallow tree via
        // parent_id (Algebra → Linear Equations) — deliberately not a
        // curriculum/syllabus engine.
        Schema::create('subject_topics', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('subject_id');
            $table->uuid('parent_id')->nullable();
            $table->string('name', 150);
            $table->string('slug', 150);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('display_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('subject_id')->references('id')->on('subjects')->cascadeOnDelete();
            $table->foreign('parent_id')->references('id')->on('subject_topics')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            // Slug is unique within its subject (Algebra can exist under
            // both Mathematics and SAT Math), not globally.
            $table->unique(['subject_id', 'slug']);
            $table->index(['subject_id', 'status', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_topics');
    }
};
