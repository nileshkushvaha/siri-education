<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Country <-> EducationSystem is many-to-many: a country may
        // offer several education systems (India: CBSE + ICSE + State
        // boards) and an international system may operate in several
        // countries (IB). This mapping row is the single source of
        // "is system X available in country Y" — never a
        // countries.education_system_id or education_systems.country_id
        // column (see docs/architecture/domain-registry.md).
        Schema::create('country_education_system', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('country_id');
            $table->uuid('education_system_id');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('country_id')->references('id')->on('countries')->cascadeOnDelete();
            $table->foreign('education_system_id')->references('id')->on('education_systems')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['country_id', 'education_system_id'], 'country_education_system_unique');
            $table->index(['country_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('country_education_system');
    }
};
