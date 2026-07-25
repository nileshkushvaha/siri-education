<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GAP-022 / SRS-7-8: an immutable published version of a HomeworkResource.
 * Its file (Media Library, private disk) never changes after publish —
 * "updating a file" creates a new row/new version instead, so an
 * assignment linked to an earlier version keeps its exact historical
 * content (SRS §7.19 "Homework history shall remain available").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homework_resource_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('homework_resource_id');
            $table->unsignedInteger('version_number');
            $table->unsignedBigInteger('created_by');
            $table->timestamp('published_at');
            $table->timestamps();

            $table->foreign('homework_resource_id')->references('id')->on('homework_resources')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();

            $table->unique(['homework_resource_id', 'version_number'], 'homework_resource_versions_resource_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homework_resource_versions');
    }
};
