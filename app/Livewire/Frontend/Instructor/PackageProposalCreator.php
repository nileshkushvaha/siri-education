<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Instructor;

use App\Booking\DTOs\BookingAcademicContextData;
use App\Booking\Services\BookingAcademicContextResolver;
use App\Models\AcademicLevel;
use App\Models\Country;
use App\Models\EducationSystem;
use App\Models\EducationSystemLevel;
use App\Models\InstructorPackageProposal;
use App\Models\PackageBenefitRule;
use App\Models\StudentPackageEntitlement;
use App\Models\StudentPackagePurchase;
use App\Models\Subject;
use App\Models\User;
use App\Package\DTOs\CreatePackageProposalData;
use App\Package\DTOs\ResolvedPackagePriceData;
use App\Package\Exceptions\PackageException;
use App\Package\Services\InstructorPackageProposalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Instructor-facing package proposal creation.
 *
 * Phase 4D turns this into the country-aware flow:
 *
 *     Student → Country (locked) → Education System →
 *     Class/Grade/Year → Subject → Package Offer → Price → Submit
 *
 * Two properties of that flow are load-bearing:
 *
 *  - The COUNTRY IS SERVER-RESOLVED and displayed read-only (§4). The
 *    instructor never chooses their student's country, and no country
 *    or currency id is accepted from this component — the service
 *    re-resolves both from the student's own profile.
 *  - The CURRICULUM IS RESOLVED, NOT CHOSEN (§11). When the selected
 *    context determines exactly one curriculum it is shown as resolved
 *    context; ambiguity or absence is surfaced as an error rather than
 *    silently picked.
 *
 * Everything shown here is a non-persisted PREVIEW. Nothing in this
 * component's state decides anything: submit() re-resolves the academic
 * context, re-checks instructor eligibility and re-resolves the price
 * server-side before freezing (§13). Price remains read-only throughout
 * — an instructor can never set or override it, the quantities, or the
 * validity.
 *
 * The legacy Subject + optional AcademicLevel shape is preserved for
 * students whose country does not have the packages feature enabled, so
 * enabling the feature per country is a genuine rollout rather than a
 * hard cutover.
 */
final class PackageProposalCreator extends Component
{
    use AuthorizesRequests;

    public bool $showForm = false;

    public string $studentId = '';

    public string $packageBenefitRuleId = '';

    public string $subjectId = '';

    /** Legacy path only — ignored entirely once the structured flow applies. */
    public string $academicLevelId = '';

    public string $educationSystemId = '';

    public string $educationSystemLevelId = '';

    /** True when the resolved student's country has country-aware packages enabled. */
    public bool $structuredFlow = false;

    /** Server-resolved, display-only. Never submitted, never trusted from the browser. */
    public ?string $studentCountryName = null;

    /** Non-persisted, read-only price preview — recomputed whenever the selections above change. */
    public ?array $preview = null;

    /** Non-persisted, read-only resolved academic context, for display. */
    public ?array $contextPreview = null;

    public ?string $previewError = null;

    private InstructorPackageProposalService $proposals;

    private BookingAcademicContextResolver $academicContext;

    public function boot(InstructorPackageProposalService $proposals, BookingAcademicContextResolver $academicContext): void
    {
        $this->proposals = $proposals;
        $this->academicContext = $academicContext;
    }

    public function openForm(): void
    {
        if (! $this->authorizeOrDeny('create', InstructorPackageProposal::class)) {
            return;
        }

        $this->resetForm();
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function cancelForm(): void
    {
        $this->resetForm();
        $this->showForm = false;
        $this->resetErrorBag();
    }

    /** Livewire's catch-all update hook — refreshes the resolved context and price preview. */
    public function updated(string $name): void
    {
        // Choosing a different student re-resolves which country (and
        // therefore which flow and which systems) applies, so every
        // downstream academic selection is cleared rather than carried
        // across to a student it may not be valid for.
        if ($name === 'studentId') {
            $this->educationSystemId = '';
            $this->educationSystemLevelId = '';
            $this->subjectId = '';
            $this->academicLevelId = '';
            $this->refreshStudentContext();
        }

        if ($name === 'educationSystemId') {
            $this->educationSystemLevelId = '';
            $this->subjectId = '';
        }

        if ($name === 'educationSystemLevelId') {
            $this->subjectId = '';
        }

        if (in_array($name, ['studentId', 'packageBenefitRuleId', 'subjectId', 'academicLevelId', 'educationSystemId', 'educationSystemLevelId'], true)) {
            $this->refreshPreview();
        }
    }

    public function submit(): void
    {
        $rules = [
            'studentId' => ['required', 'integer'],
            'packageBenefitRuleId' => ['required', 'string'],
            'subjectId' => ['required', 'string'],
        ];

        // Structured flow makes the academic selection mandatory — there
        // is deliberately no silent fallback to the legacy shape once
        // the feature is in effect for this student's country (§35).
        if ($this->structuredFlow) {
            $rules['educationSystemId'] = ['required', 'string'];
            $rules['educationSystemLevelId'] = ['required', 'string'];
        } else {
            $rules['academicLevelId'] = ['nullable', 'string'];
        }

        $this->validate($rules);

        // Ownership, eligibility, academic context and price are ALL
        // re-verified server-side inside the service regardless of what
        // the client submitted — the pickers below are already scoped,
        // but browser state can never be trusted for the decision.
        try {
            $proposal = $this->proposals->proposeAndSubmit(new CreatePackageProposalData(
                instructorId: (int) auth()->id(),
                studentId: (int) $this->studentId,
                packageBenefitRuleId: $this->packageBenefitRuleId,
                subjectId: $this->subjectId,
                academicLevelId: $this->academicLevelId !== '' ? $this->academicLevelId : null,
                educationSystemId: $this->educationSystemId !== '' ? $this->educationSystemId : null,
                educationSystemLevelId: $this->educationSystemLevelId !== '' ? $this->educationSystemLevelId : null,
            ));
        } catch (PackageException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        $this->resetForm();
        $this->showForm = false;
        session()->flash('package-proposal-status', sprintf('Package proposal submitted for %s — awaiting admin review.', $proposal->student?->name));
    }

    public function render(): View
    {
        $instructor = auth()->user();

        return view('livewire.frontend.instructor.package-proposal-creator', [
            'eligibleStudents' => $this->proposals->eligibleStudentsFor($instructor),
            'benefitRules' => PackageBenefitRule::query()->active()->orderBy('name')->get(),
            'educationSystems' => $this->availableEducationSystems(),
            'educationSystemLevels' => $this->availableLevels(),
            'levelTerm' => $this->levelTerm(),
            'subjects' => $this->availableSubjects(),
            'academicLevels' => AcademicLevel::query()->active()->orderBy('name')->get(),
            'proposals' => InstructorPackageProposal::query()
                ->forInstructor((int) $instructor->id)
                ->with(['student', 'packageBenefitRule', 'academicContext'])
                ->orderByDesc('created_at')
                ->paginate(10),
            // Read-only commercial status of this instructor's own
            // accepted packages, keyed by proposal_id. Strictly view
            // only: an instructor can never pay for, cancel, or settle
            // a student's purchase.
            'purchases' => StudentPackagePurchase::query()
                ->whereIn('proposal_id', InstructorPackageProposal::query()
                    ->forInstructor((int) $instructor->id)
                    ->select('id'))
                ->get()
                ->keyBy('proposal_id'),
            // Read-only lesson balances for this instructor's own
            // accepted packages, keyed by proposal_id. The instructor may
            // never modify an entitlement — only view it.
            'entitlements' => StudentPackageEntitlement::query()
                ->forInstructor((int) $instructor->id)
                ->get()
                ->keyBy('proposal_id'),
        ]);
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function resetForm(): void
    {
        $this->reset([
            'studentId', 'packageBenefitRuleId', 'subjectId', 'academicLevelId',
            'educationSystemId', 'educationSystemLevelId', 'structuredFlow',
            'studentCountryName', 'preview', 'contextPreview', 'previewError',
        ]);
    }

    /** Resolves the selected student's country server-side and decides which flow applies. */
    private function refreshStudentContext(): void
    {
        $this->structuredFlow = false;
        $this->studentCountryName = null;

        $student = $this->selectedStudent();

        if ($student === null) {
            return;
        }

        $country = $this->academicContext->studentCountry($student);
        $this->studentCountryName = $country?->name;
        $this->structuredFlow = $this->proposals->structuredContextRequiredFor($student);
    }

    private function selectedStudent(): ?User
    {
        if ($this->studentId === '') {
            return null;
        }

        $student = User::find($this->studentId);

        // Never resolve context for a student this instructor has no
        // relationship with — the picker is scoped, but a forged id
        // must not leak another student's country either.
        if ($student === null || ! $this->proposals->hasValidRelationship(auth()->user(), $student)) {
            return null;
        }

        return $student;
    }

    private function studentCountry(): ?Country
    {
        $student = $this->selectedStudent();

        return $student !== null ? $this->academicContext->studentCountry($student) : null;
    }

    /** @return Collection<int, EducationSystem> */
    private function availableEducationSystems(): Collection
    {
        $country = $this->studentCountry();

        if (! $this->structuredFlow || $country === null) {
            return new Collection;
        }

        // Narrowed to the instructor's own eligibility so they are never
        // offered a system they cannot teach under (§10).
        return $this->academicContext->educationSystemsFor($country, auth()->user());
    }

    /**
     * The student-selectable levels for the chosen system — Class 10 /
     * Grade 10 / Year 10, from EducationSystemLevel. Never a synthesized
     * 1..12 range (§6).
     *
     * @return Collection<int, EducationSystemLevel>
     */
    private function availableLevels(): Collection
    {
        $country = $this->studentCountry();
        $system = $this->selectedSystem();

        if ($country === null || $system === null) {
            return new Collection;
        }

        return $this->academicContext->levelsFor($country, $system);
    }

    /** The system's own configured terminology — "Class", "Grade", "Year", … never a country if/else. */
    private function levelTerm(): string
    {
        return $this->selectedSystem()?->levelTermSingular() ?? 'Level';
    }

    private function selectedSystem(): ?EducationSystem
    {
        if (! $this->structuredFlow || $this->educationSystemId === '') {
            return null;
        }

        return $this->availableEducationSystemsCached()
            ->firstWhere('id', $this->educationSystemId);
    }

    /** @return Collection<int, EducationSystem> */
    private function availableEducationSystemsCached(): Collection
    {
        $country = $this->studentCountry();

        if ($country === null) {
            return new Collection;
        }

        return $this->academicContext->educationSystemsFor($country, auth()->user());
    }

    /** @return Collection<int, Subject> */
    private function availableSubjects(): Collection
    {
        // Legacy path keeps the unrestricted active-subject list it has
        // always had; the structured path narrows to what is actually
        // available for this exact Country + System + Level.
        if (! $this->structuredFlow) {
            return Subject::query()->active()->orderBy('name')->get();
        }

        $country = $this->studentCountry();
        $system = $this->selectedSystem();
        $level = $this->selectedLevel();

        if ($country === null || $system === null || $level === null) {
            return new Collection;
        }

        return $this->academicContext->subjectsFor($country, $system, $level, auth()->user());
    }

    private function selectedLevel(): ?EducationSystemLevel
    {
        if ($this->educationSystemLevelId === '') {
            return null;
        }

        return $this->availableLevels()->firstWhere('id', $this->educationSystemLevelId);
    }

    private function refreshPreview(): void
    {
        $this->preview = null;
        $this->contextPreview = null;
        $this->previewError = null;

        if ($this->studentId === '' || $this->packageBenefitRuleId === '' || $this->subjectId === '') {
            return;
        }

        $instructor = auth()->user();
        $student = $this->selectedStudent();
        $rule = PackageBenefitRule::query()->active()->find($this->packageBenefitRuleId);
        $subject = Subject::query()->find($this->subjectId);

        if ($student === null || $rule === null || $subject === null) {
            return;
        }

        if (! $this->proposals->hasValidRelationship($instructor, $student)) {
            $this->previewError = 'This instructor has no existing paid relationship with this student.';

            return;
        }

        // Resolves through the SAME path submit() uses, so the preview
        // and the eventual frozen snapshot can differ only in timing.
        $context = null;

        if ($this->structuredFlow) {
            if ($this->educationSystemId === '' || $this->educationSystemLevelId === '') {
                return;
            }

            try {
                $context = $this->proposals->previewContext(
                    $student,
                    $instructor,
                    $this->educationSystemId,
                    $this->educationSystemLevelId,
                    $subject,
                );
            } catch (PackageException $e) {
                $this->previewError = $e->getMessage();

                return;
            }

            $this->contextPreview = $context !== null ? $this->contextArray($context) : null;
        }

        // The AcademicLevel used for pricing is the DERIVED one in the
        // structured flow — never the instructor's posted value.
        $academicLevel = $context !== null
            ? AcademicLevel::query()->find($context->academicLevelId)
            : ($this->academicLevelId !== '' ? AcademicLevel::query()->find($this->academicLevelId) : null);

        try {
            $price = $this->proposals->previewPrice($student, $instructor, $rule, $subject, $academicLevel);
        } catch (PackageException $e) {
            $this->previewError = $e->getMessage();

            return;
        }

        $this->preview = $this->previewArray($price, $rule);
    }

    /**
     * Display-only projection of the resolved context. Deliberately
     * exposes names and labels, never internal ids (§11).
     *
     * @return array<string, mixed>
     */
    private function contextArray(BookingAcademicContextData $context): array
    {
        return [
            'country_name' => $context->countryName,
            'education_system_name' => $context->educationSystemName,
            'level_term' => $context->levelTerm,
            'level_display' => $context->levelDisplay,
            'subject_name' => $context->subjectName,
            'curriculum_name' => $context->curriculumName,
            'curriculum_version_number' => $context->curriculumVersionNumber,
        ];
    }

    /** @return array<string, mixed> */
    private function previewArray(ResolvedPackagePriceData $price, PackageBenefitRule $rule): array
    {
        return [
            'unit_price_minor' => $price->unitPriceMinor,
            'paid_quantity' => $rule->paid_quantity,
            'bonus_quantity' => $rule->bonus_quantity,
            'total_quantity' => $rule->total_quantity,
            'validity_days' => $rule->validity_days,
            'calculated_price_minor' => $price->calculatedPriceMinor,
            'currency_code' => $price->currencyCode,
        ];
    }

    private function authorizeOrDeny(string $ability, mixed $arg): bool
    {
        try {
            $this->authorize($ability, $arg);

            return true;
        } catch (AuthorizationException $e) {
            $this->addError('form', $e->getMessage() ?: 'You are not authorized to perform this action.');

            return false;
        }
    }
}
