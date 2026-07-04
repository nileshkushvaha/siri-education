<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_availability', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('teacher_id');
            $table->unsignedTinyInteger('day_of_week'); // Carbon numbering: Sunday = 0
            $table->time('start_time');
            $table->time('end_time');
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('teacher_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['teacher_id', 'day_of_week', 'is_active']);
        });

        DB::statement('ALTER TABLE teacher_availability ADD CONSTRAINT chk_teacher_availability_time_range CHECK (start_time < end_time)');
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_availability');
    }
};
