<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Modules belong to exactly one curriculum_version — never
        // shared/mutable across versions (SRS Book 2 §4.10/§5.9).
        // Creating a new version means creating a new set of module
        // rows, not repointing existing ones.
        Schema::create('curriculum_modules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('curriculum_version_id');
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('curriculum_version_id')->references('id')->on('curriculum_versions')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['curriculum_version_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curriculum_modules');
    }
};
