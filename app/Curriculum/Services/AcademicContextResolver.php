<?php

declare(strict_types=1);

namespace App\Curriculum\Services;

use App\Curriculum\DTOs\AcademicContextData;
use App\Curriculum\Enums\CurriculumVersionStatus;
use App\Curriculum\Exceptions\AcademicContextException;
use App\Enums\AcademicStatus;
use App\Models\AcademicLevel;
use App\Models\Country;
use App\Models\Curriculum;
use App\Models\CurriculumVersion;
use App\Models\EducationSystem;
use App\Models\EducationSystemLevel;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The single authority on which academic choices are valid for a
 * given Country, mirroring how CountryFeatureResolver is the single
 * authority on effective feature flags (app/Country/Services). Lives
 * in the Curriculum domain (app/Curriculum/Services) rather than
 * app/Country/Services because every option it resolves ultimately
 * bottoms out in Curriculum/CurriculumVersion — the Education System
 * layer this class introduces is additive configuration hung off the
 * Curriculum domain established in Phase 0A, not a new top-level
 * domain of its own.
 *
 * Intended as the shared source for demo booking, paid booking,
 * recurring booking, instructor academic eligibility, and
 * personalized package creation once those phases are built — none of
 * that exists yet; this class only resolves server-side academic
 * options and validates combinations. It never touches Booking,
 * Pricing, Wallet, or instructor eligibility.
 *
 * Validation always intersects with pre-existing constraints —
 * Subject::isAvailableInCountry() remains authoritative for country
 * subject availability, never duplicated here.
 *
 * Legacy-compatibility rule (SRS Book 2 §4.9 predates Education
 * Systems): a Curriculum with zero EducationSystem mappings is
 * globally applicable and resolves under ANY Education System/Country
 * combination that is otherwise valid for its Subject + AcademicLevel.
 * A Curriculum with one or more mappings resolves ONLY for the
 * mapped Education System(s) — this is deliberate, not ambiguous:
 * once an admin has scoped a curriculum, unscoped access would be a
 * silent security/product regression.
 *
 * "Current published version" rule: as of Phase 1.1,
 * CurriculumService::publish() enforces a write-side invariant — it
 * auto-archives a curriculum's prior Published version inside the
 * same transaction as the new publish, so normal valid state contains
 * at most one Published version per Curriculum. This resolver never
 * picks latest(id) or latest(created_at) — it reuses
 * Curriculum::latestPublishedVersion(), which is defined as the
 * HIGHEST version_number currently Published for that curriculum.
 * That remains the one authoritative "current version" rule for this
 * domain. If more than one Published row is ever found (legacy data
 * predating the Phase 1.1 invariant, or a direct DB mutation outside
 * the service), that is treated as corrupt/legacy data, not expected
 * domain behavior: the resolver still fails safe by deterministically
 * returning the highest version_number (never silently picks an
 * arbitrary one and never throws, since a reader must remain able to
 * resolve a "current" version for booking/enrollment even against
 * historical data) and logs a warning so the anomaly is discoverable
 * without introducing new alerting infrastructure.
 *
 * Subject vs. Curriculum vs. resolved Academic Context (Phase 1.1):
 * these are three distinct questions and must not be conflated.
 * subjectsForContext() answers "do we teach this Subject in this
 * Country/System/Level" — regional academic visibility, which does
 * NOT require any Curriculum (let alone a Published one) to exist;
 * it is governed solely by Subject::isAvailableInCountry() plus the
 * Subject/Country/System/Level being active and correctly mapped.
 * curriculaForContext() answers the stricter "which curricula are
 * currently selectable" question and continues to require a
 * currentPublishedVersion(). resolveContext() answers the strictest
 * question — "is this exact, fully-specified combination bookable
 * right now" — and still throws AcademicContextException when no
 * Published CurriculumVersion exists. A Subject being visible never
 * implies a Curriculum is selectable, and a Curriculum being
 * selectable never implies the final context resolves without also
 * satisfying every other link in the chain.
 */
final class AcademicContextResolver
{
    /** Education systems available (active + actively mapped) in the given country, ordered for UI display. */
    public function educationSystemsForCountry(Country $country): Collection
    {
        if ($country->status !== 'active') {
            return new Collection;
        }

        return EducationSystem::query()
            ->active()
            ->whereHas('countryMappings', fn ($q) => $q->where('country_id', $country->id)->where('is_active', true))
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();
    }

    /** Academic levels available for the given system in the given country. */
    public function academicLevelsForSystem(Country $country, EducationSystem $system): Collection
    {
        $this->assertSystemAvailableInCountry($country, $system);

        return AcademicLevel::query()
            ->active()
            ->whereHas('educationSystemMappings', fn ($q) => $q->where('education_system_id', $system->id)->where('is_active', true))
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Phase 3.1 — the exact, student-selectable levels under the given
     * system (Class 6..12 / Grade 6..12 / Year 6..12, ...), in place of
     * the broad AcademicLevel band a student never chooses directly.
     * Returns an empty Collection when none are configured — callers
     * must never synthesize a 1..12 fallback (§7/§39).
     */
    public function levelsForSystem(Country $country, EducationSystem $system): Collection
    {
        $this->assertSystemAvailableInCountry($country, $system);

        return EducationSystemLevel::query()
            ->active()
            ->where('education_system_id', $system->id)
            ->whereHas('academicLevel', fn ($q) => $q->where('status', AcademicStatus::Active))
            ->orderBy('display_order')
            ->orderBy('value')
            ->get();
    }

    /**
     * Phase 3.1 — resolves academic context from a student-selected
     * EducationSystemLevel instead of requiring the caller to
     * independently supply an AcademicLevel. Derives the AcademicLevel
     * from the level and delegates entirely to resolveContext() — no
     * parallel resolution logic.
     */
    public function resolveContextForLevel(Country $country, EducationSystem $system, EducationSystemLevel $level, Subject $subject, Curriculum $curriculum): AcademicContextData
    {
        if ($level->education_system_id !== $system->id) {
            throw new AcademicContextException('Selected level does not belong to the selected education system.');
        }

        if (! $level->is_active) {
            throw new AcademicContextException(sprintf('Level "%s" is not active.', $level->display_label));
        }

        $academicLevel = $level->academicLevel;

        if ($academicLevel === null || $academicLevel->status !== AcademicStatus::Active) {
            throw new AcademicContextException(sprintf('Level "%s" is not linked to an active academic level.', $level->display_label));
        }

        return $this->resolveContext($country, $system, $academicLevel, $subject, $curriculum);
    }

    /**
     * Subjects regionally available for Country X + System Y + Level Z
     * (SRS "regional Subject availability" / "Subject availability by
     * Country" — Chapter 7/8): active, and available in the country
     * per the pre-existing Subject::isAvailableInCountry() rule, once
     * the System/Level context itself is confirmed valid.
     *
     * Deliberately does NOT require any Curriculum — let alone a
     * Published one — to exist for the subject (Phase 1.1 correction;
     * see class docblock "Subject vs. Curriculum vs. resolved Academic
     * Context"). "We teach this Subject in this country" and "we
     * currently have a selectable curriculum for it" are different
     * questions; curriculaForContext() answers the second one. No
     * direct Subject↔EducationSystem or Subject↔AcademicLevel mapping
     * model exists in this domain, so once System/Level validity is
     * established, country availability is the only additional signal
     * that applies here.
     */
    public function subjectsForContext(Country $country, EducationSystem $system, AcademicLevel $level): Collection
    {
        $this->assertSystemAvailableInCountry($country, $system);
        $this->assertLevelMappedToSystem($system, $level);

        return Subject::query()
            ->active()
            ->get()
            ->filter(fn (Subject $subject): bool => $subject->isAvailableInCountry($country))
            ->sortBy('name')
            ->values();
    }

    /** Curricula available for Country X + System Y + Level Z + Subject S, each carrying a selectable published version. */
    public function curriculaForContext(Country $country, EducationSystem $system, AcademicLevel $level, Subject $subject): Collection
    {
        $this->assertSystemAvailableInCountry($country, $system);
        $this->assertLevelMappedToSystem($system, $level);
        $this->assertSubjectAvailableInCountry($country, $subject);

        return $this->eligibleCurriculaQuery($system, $level)
            ->where('subject_id', $subject->id)
            ->get()
            ->filter(fn (Curriculum $curriculum): bool => $this->currentPublishedVersion($curriculum) !== null)
            ->values();
    }

    /**
     * The authoritative "current" published version for a curriculum
     * — see class docblock. Normal valid state (Phase 1.1) contains at
     * most one Published row; if more than one is found, that is
     * legacy/corrupt data, not expected domain behavior — this method
     * still resolves deterministically (highest version_number) and
     * logs a warning so the anomaly is discoverable, rather than
     * throwing and breaking every read path that touches this
     * curriculum.
     */
    public function currentPublishedVersion(Curriculum $curriculum): ?CurriculumVersion
    {
        $published = $curriculum->versions()
            ->where('status', CurriculumVersionStatus::Published)
            ->orderByDesc('version_number')
            ->get();

        if ($published->count() > 1) {
            Log::warning('Curriculum has more than one Published CurriculumVersion — resolving to the highest version_number; this indicates legacy/corrupt data predating the Phase 1.1 single-Published invariant.', [
                'curriculum_id' => $curriculum->id,
                'published_version_ids' => $published->pluck('id')->all(),
                'published_version_numbers' => $published->pluck('version_number')->all(),
            ]);
        }

        return $published->first();
    }

    /**
     * Runs the full validation chain and returns a fully-resolved,
     * trusted AcademicContextData. Throws AcademicContextException on
     * any invalid link in the chain — this is the only place client-
     * supplied ids are turned into a trusted context.
     */
    public function resolveContext(Country $country, EducationSystem $system, AcademicLevel $level, Subject $subject, Curriculum $curriculum): AcademicContextData
    {
        $this->assertSystemAvailableInCountry($country, $system);
        $this->assertLevelMappedToSystem($system, $level);
        $this->assertSubjectAvailableInCountry($country, $subject);
        $this->assertCurriculumMatchesContext($curriculum, $system, $level, $subject);

        $version = $this->currentPublishedVersion($curriculum);

        if ($version === null) {
            throw new AcademicContextException(sprintf('Curriculum "%s" has no published version available for selection.', $curriculum->name));
        }

        return new AcademicContextData(
            countryId: $country->id,
            educationSystemId: $system->id,
            academicLevelId: $level->id,
            subjectId: $subject->id,
            curriculumId: $curriculum->id,
            curriculumVersionId: $version->id,
        );
    }

    /** Non-throwing variant of resolveContext() for eligibility checks that only need a boolean. */
    public function isValidContext(Country $country, EducationSystem $system, AcademicLevel $level, Subject $subject, Curriculum $curriculum): bool
    {
        try {
            $this->resolveContext($country, $system, $level, $subject, $curriculum);

            return true;
        } catch (AcademicContextException) {
            return false;
        }
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function eligibleCurriculaQuery(EducationSystem $system, AcademicLevel $level)
    {
        return Curriculum::query()
            ->where('academic_level_id', $level->id)
            ->where(fn ($q) => $q
                ->doesntHave('educationSystemMappings')
                ->orWhereHas('educationSystemMappings', fn ($m) => $m->where('education_system_id', $system->id)));
    }

    private function assertSystemAvailableInCountry(Country $country, EducationSystem $system): void
    {
        if ($country->status !== 'active') {
            throw new AcademicContextException(sprintf('Country "%s" is not active.', $country->name));
        }

        if ($system->status !== AcademicStatus::Active) {
            throw new AcademicContextException(sprintf('Education system "%s" is not active.', $system->name));
        }

        $mapped = $system->countryMappings()
            ->where('country_id', $country->id)
            ->where('is_active', true)
            ->exists();

        if (! $mapped) {
            throw new AcademicContextException(sprintf('Education system "%s" is not available in "%s".', $system->name, $country->name));
        }
    }

    private function assertLevelMappedToSystem(EducationSystem $system, AcademicLevel $level): void
    {
        if ($level->status !== AcademicStatus::Active) {
            throw new AcademicContextException(sprintf('Academic level "%s" is not active.', $level->name));
        }

        $mapped = $system->academicLevelMappings()
            ->where('academic_level_id', $level->id)
            ->where('is_active', true)
            ->exists();

        if (! $mapped) {
            throw new AcademicContextException(sprintf('Academic level "%s" is not mapped to education system "%s".', $level->name, $system->name));
        }
    }

    private function assertSubjectAvailableInCountry(Country $country, Subject $subject): void
    {
        if ($subject->status !== AcademicStatus::Active) {
            throw new AcademicContextException(sprintf('Subject "%s" is not active.', $subject->name));
        }

        if (! $subject->isAvailableInCountry($country)) {
            throw new AcademicContextException(sprintf('Subject "%s" is not available in "%s".', $subject->name, $country->name));
        }
    }

    private function assertCurriculumMatchesContext(Curriculum $curriculum, EducationSystem $system, AcademicLevel $level, Subject $subject): void
    {
        if ($curriculum->subject_id !== $subject->id) {
            throw new AcademicContextException(sprintf('Curriculum "%s" does not belong to subject "%s".', $curriculum->name, $subject->name));
        }

        if ($curriculum->academic_level_id !== $level->id) {
            throw new AcademicContextException(sprintf('Curriculum "%s" does not belong to academic level "%s".', $curriculum->name, $level->name));
        }

        $hasMappings = $curriculum->educationSystemMappings()->exists();

        if ($hasMappings && ! $curriculum->educationSystemMappings()->where('education_system_id', $system->id)->exists()) {
            throw new AcademicContextException(sprintf('Curriculum "%s" is not mapped to education system "%s".', $curriculum->name, $system->name));
        }
    }
}
