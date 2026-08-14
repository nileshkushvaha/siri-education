<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4D — the immutable, structured academic identity of ONE
 * personalized package proposal.
 *
 * Before this, a proposal carried only `subject_id` + `academic_level_id`
 * while country-aware booking had grown a full
 * Country → EducationSystem → EducationSystemLevel → AcademicLevel →
 * Subject → Curriculum → CurriculumVersion chain. That left a package
 * meaning "Math + AcademicLevel 10" facing a booking meaning "India /
 * CBSE / Class 10 / Mathematics / Curriculum X v3" — two academic
 * truths that cannot be matched deterministically. This table gives the
 * package the same structured identity the booking already has, so
 * entitlement↔booking eligibility can be decided on stable ids rather
 * than fuzzy display matching.
 *
 * Deliberately a SEPARATE table from booking_academic_contexts rather
 * than making that one polymorphic (spec §1). The two have different
 * lifecycle ownership: a booking snapshot is frozen at booking creation
 * and belongs to one historical lesson, whereas a package snapshot is
 * frozen at proposal SUBMISSION and then governs many future bookings.
 * Sharing a table would couple those lifecycles and put a morph column
 * on the hottest historical record in the booking domain for no gain.
 * The resolution ALGORITHM is shared instead
 * (BookingAcademicContextResolver), which is where duplication would
 * actually have hurt.
 *
 * Ownership (spec §2): this row hangs off the PROPOSAL, exactly once.
 * Purchases and entitlements reach it THROUGH the proposal
 * (entitlement → proposal → academicContext) rather than each carrying
 * their own copy — four copies of one snapshot would be four things
 * that can disagree. The pre-existing `subject_id`/`academic_level_id`
 * columns on proposals and entitlements are kept for compatibility and
 * cheap querying, and are written from THIS snapshot at submission, so
 * they can only ever agree with it.
 *
 * Historical immutability (spec §3): the denormalized name/code/label
 * columns are the authoritative historical record. A later rename of an
 * Education System, a relabelled Level, or a newly published
 * CurriculumVersion must never rewrite what an already-submitted
 * package represented — the frozen curriculum_version_id stays put and
 * only a NEW proposal resolves the newer version. Master-data FKs are
 * therefore nullOnDelete (never cascade): an archived master must
 * neither block nor destroy package history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_academic_contexts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // One structured identity per proposal, forever.
            $table->uuid('proposal_id')->unique();

            $table->unsignedBigInteger('country_id')->nullable();
            $table->string('country_code', 10)->nullable();
            $table->string('country_name')->nullable();

            $table->uuid('education_system_id')->nullable();
            $table->string('education_system_code', 50)->nullable();
            $table->string('education_system_name')->nullable();

            $table->uuid('academic_level_id')->nullable();
            $table->string('academic_level_name')->nullable();

            $table->uuid('education_system_level_id')->nullable();
            $table->string('level_term', 50)->nullable();
            $table->string('level_value', 50)->nullable();
            $table->string('level_display', 100)->nullable();
            $table->unsignedTinyInteger('normalized_grade')->nullable();

            $table->uuid('subject_id')->nullable();
            $table->string('subject_name')->nullable();
            $table->string('subject_slug')->nullable();

            $table->uuid('curriculum_id')->nullable();
            $table->string('curriculum_name')->nullable();
            $table->string('curriculum_slug')->nullable();

            $table->uuid('curriculum_version_id')->nullable();
            $table->unsignedInteger('curriculum_version_number')->nullable();

            $table->timestamps();

            // Package academic history is package history — never wiped
            // by a proposal-row operation (mirrors booking_academic_contexts).
            $table->foreign('proposal_id')->references('id')->on('instructor_package_proposals')->restrictOnDelete();
            $table->foreign('country_id')->references('id')->on('countries')->nullOnDelete();
            $table->foreign('education_system_id')->references('id')->on('education_systems')->nullOnDelete();
            $table->foreign('academic_level_id')->references('id')->on('academic_levels')->nullOnDelete();
            $table->foreign('education_system_level_id')->references('id')->on('education_system_levels')->nullOnDelete();
            $table->foreign('subject_id')->references('id')->on('subjects')->nullOnDelete();
            $table->foreign('curriculum_id')->references('id')->on('curricula')->nullOnDelete();
            $table->foreign('curriculum_version_id')->references('id')->on('curriculum_versions')->nullOnDelete();

            // The composite index the entitlement-eligibility resolver
            // matches on (spec §15: stable ids, never display labels).
            $table->index(['education_system_id', 'education_system_level_id', 'subject_id'], 'pac_system_level_subject_index');
            $table->index('country_id');
            $table->index('curriculum_id');
            $table->index('curriculum_version_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_academic_contexts');
    }
};
