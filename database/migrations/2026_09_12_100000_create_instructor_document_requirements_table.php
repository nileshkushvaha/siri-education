<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructor_document_requirements', function (Blueprint $table): void {
            $table->id();
            $table->string('collection_name', 60)->unique();
            $table->string('label', 150);
            $table->text('description')->nullable();
            $table->boolean('required')->default(true);
            $table->json('accepted_mime_types');
            $table->unsignedInteger('max_size_kb')->default(4096);
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_document_requirements');
    }
};
