<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Point-in-time curriculum state (SRS Book 2 §5.8 "Curriculum
        // Versions"). Sequential version_number is the stable,
        // auditable identifier future Learning Plans can reference —
        // never renumbered, never reused. Published rows are never
        // destructively edited (enforced in CurriculumService, not
        // just here); a "change" always creates a new version row.
        Schema::create('curriculum_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('curriculum_id');
            $table->unsignedInteger('version_number');
            // draft | published | archived | retired — CurriculumVersionStatus
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('curriculum_id')->references('id')->on('curricula')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            // Deterministic, race-safe versioning: a curriculum can
            // never have two rows claiming the same version_number.
            $table->unique(['curriculum_id', 'version_number']);
            $table->index(['curriculum_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curriculum_versions');
    }
};
