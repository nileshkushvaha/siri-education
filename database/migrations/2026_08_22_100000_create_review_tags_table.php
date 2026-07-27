<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configured review tags — students select from this
 * fixed set, never free text. `applicable_modes` restricts a tag to
 * public_review and/or private_feedback submissions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_tags', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key', 64)->unique();
            $table->string('label', 100);
            $table->json('applicable_modes'); // ['public_review', 'private_feedback']
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_tags');
    }
};
