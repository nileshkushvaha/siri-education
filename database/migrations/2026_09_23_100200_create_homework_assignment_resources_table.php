<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS-7-8: links a homework assignment to a specific,
 * immutable resource version — the "reuse across lessons" half of the
 * resource library. The unique index is the database-level guarantee
 * that the same version can never be linked twice to the same
 * assignment (requirement #5). Detaching deletes the row; historical
 * evidence of the attach/detach lives in the audit log, not here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homework_assignment_resources', function (Blueprint $table): void {
            $table->id();
            $table->uuid('homework_assignment_id');
            $table->uuid('homework_resource_version_id');
            $table->unsignedBigInteger('attached_by')->nullable();
            $table->timestamps();

            $table->foreign('homework_assignment_id', 'har_homework_assignment_id_foreign')
                ->references('id')->on('homework_assignments')->cascadeOnDelete();
            $table->foreign('homework_resource_version_id', 'har_resource_version_id_foreign')
                ->references('id')->on('homework_resource_versions')->restrictOnDelete();
            $table->foreign('attached_by', 'har_attached_by_foreign')
                ->references('id')->on('users')->nullOnDelete();

            $table->unique(['homework_assignment_id', 'homework_resource_version_id'], 'homework_assignment_resource_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homework_assignment_resources');
    }
};
