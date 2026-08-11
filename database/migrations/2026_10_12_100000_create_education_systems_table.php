<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Configuration/reference data describing an academic
        // framework/program an instructor or student may follow
        // (e.g. CBSE, ICSE, IB, GCSE, SAT, AP, "US Common Academic
        // System"). Deliberately generic — no board-specific model or
        // enum. Country/Level/Curriculum applicability is expressed
        // through the mapping tables created alongside this one, not
        // through direct foreign keys here, because the same system
        // may apply to several countries/levels/curricula and vice
        // versa (see docs/architecture/domain-registry.md).
        Schema::create('education_systems', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 150);
            $table->string('slug', 150)->unique();
            $table->string('code', 30)->nullable();
            $table->text('description')->nullable();
            // active | inactive | archived — App\Enums\AcademicStatus
            $table->string('status', 20)->default('active');
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index('status');
            $table->index('display_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('education_systems');
    }
};
