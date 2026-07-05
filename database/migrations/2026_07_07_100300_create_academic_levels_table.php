<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_levels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->text('description')->nullable();
            /** Inclusive grade range this level maps onto (e.g. Primary = 1-5). Null = not grade-bound (e.g. Undergraduate). */
            $table->unsignedTinyInteger('min_grade')->nullable();
            $table->unsignedTinyInteger('max_grade')->nullable();
            // active | inactive | archived — App\Enums\AcademicStatus
            $table->string('status', 20)->default('active');
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('display_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_levels');
    }
};
