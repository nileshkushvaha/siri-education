<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Personalized Instructor Package Proposal foundation — admin-managed,
 * reusable quantity rules (e.g. "14 paid lessons + 1 bonus lesson").
 * Deliberately carries no price of any kind: price is resolved per
 * proposal from the existing StudentLessonPrice matrix, never stored
 * or configured here (see InstructorPackageProposal). Historical
 * safety: an InstructorPackageProposal snapshots the quantities used
 * at submission time onto its own row, so a later admin edit here
 * never rewrites an already-submitted proposal's numbers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_benefit_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 150);
            $table->unsignedSmallInteger('paid_quantity');
            $table->unsignedSmallInteger('bonus_quantity')->default(0);
            $table->unsignedSmallInteger('total_quantity');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index('is_active');
        });

        DB::statement('ALTER TABLE package_benefit_rules ADD CONSTRAINT chk_package_benefit_rules_quantity CHECK (total_quantity = paid_quantity + bonus_quantity)');
    }

    public function down(): void
    {
        Schema::dropIfExists('package_benefit_rules');
    }
};
