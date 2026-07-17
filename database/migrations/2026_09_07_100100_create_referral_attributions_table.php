<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_attributions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('referrer_id')->constrained('users')->restrictOnDelete();
            // The single-referrer invariant: one attribution per referred
            // student, forever. The unique index is the final guard —
            // service-level checks alone cannot survive concurrent
            // duplicate submissions.
            $table->foreignId('referred_student_id')->unique()->constrained('users')->restrictOnDelete();
            $table->foreignId('referral_code_id')->constrained('referral_codes')->restrictOnDelete();
            $table->string('source');
            $table->timestamp('attributed_at');
            $table->timestamps();
            // referrer_id and referral_code_id lookups are served by the
            // indexes MySQL creates for their foreign keys — no extra
            // single-column indexes needed.
        });

        DB::statement('ALTER TABLE referral_attributions ADD CONSTRAINT chk_referral_attributions_no_self CHECK (referrer_id <> referred_student_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_attributions');
    }
};
