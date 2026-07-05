<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A subject with no rows here is available in every country (the
 * default) — rows only exist to SCOPE a subject to a specific subset
 * of countries when that's actually needed. See Subject::isAvailableInCountry().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subject_country', function (Blueprint $table): void {
            $table->uuid('subject_id');
            $table->unsignedBigInteger('country_id');
            $table->timestamps();

            $table->foreign('subject_id')->references('id')->on('subjects')->cascadeOnDelete();
            $table->foreign('country_id')->references('id')->on('countries')->cascadeOnDelete();
            $table->unique(['subject_id', 'country_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_country');
    }
};
