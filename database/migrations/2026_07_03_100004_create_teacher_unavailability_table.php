<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_unavailability', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('teacher_id');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->foreign('teacher_id')->references('id')->on('users')->cascadeOnDelete();
            // Overlap lookups: WHERE teacher_id = ? AND starts_at < ? AND ends_at > ?
            $table->index(['teacher_id', 'starts_at', 'ends_at']);
        });

        DB::statement('ALTER TABLE teacher_unavailability ADD CONSTRAINT chk_teacher_unavailability_time_range CHECK (starts_at < ends_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_unavailability');
    }
};
