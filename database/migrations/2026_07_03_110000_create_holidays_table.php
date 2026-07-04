<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Organisation-wide non-working days — apply to every teacher,
        // unlike teacher_unavailability which is per-teacher leave.
        Schema::create('holidays', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->date('date')->unique();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
