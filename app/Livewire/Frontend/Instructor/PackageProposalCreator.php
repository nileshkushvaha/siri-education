<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Instructor;

use App\Models\AcademicLevel;
use App\Models\InstructorPackageProposal;
use App\Models\PackageBenefitRule;
use App\Models\Subject;
use App\Models\User;
use App\Package\DTOs\CreatePackageProposalData;
use App\Package\DTOs\ResolvedPackagePriceData;
use App\Package\Exceptions\PackageException;
use App\Package\Services\InstructorPackageProposalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * Instructor-facing package proposal creation. The price field is
 * always read-only/server-computed — the instructor selects a student,
 * an admin-approved package rule, and optional academic context; the
 * estimated price shown is a live, non-persisted preview from
 * PackagePricingService, never something the instructor can edit.
 * Submitting calls InstructorPackageProposalService::proposeAndSubmit()
 * directly (create()+submit() in one transaction) — the instructor has
 * no separate "save draft" step, matching the single "Submit" action
 * this phase's spec calls for.
 */
final class PackageProposalCreator extends Component
{
    use AuthorizesRequests;

    public bool $showForm = false;

    public string $studentId = '';

    public string $packageBenefitRuleId = '';

    public string $subjectId = '';

    public string $academicLevelId = '';

    /** Non-persisted, read-only price preview — recomputed whenever the selections above change. */
    public ?array $preview = null;

    public ?string $previewError = null;

    private InstructorPackageProposalService $proposals;

    public function boot(InstructorPackageProposalService $proposals): void
    {
        $this->proposals = $proposals;
    }

    public function openForm(): void
    {
        if (! $this->authorizeOrDeny('create', InstructorPackageProposal::class)) {
            return;
        }

        $this->reset(['studentId', 'packageBenefitRuleId', 'subjectId', 'academicLevelId', 'preview', 'previewError']);
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function cancelForm(): void
    {
        $this->reset(['showForm', 'studentId', 'packageBenefitRuleId', 'subjectId', 'academicLevelId', 'preview', 'previewError']);
        $this->resetErrorBag();
    }

    /** Livewire's catch-all update hook — refreshes the price preview whenever a selection changes. */
    public function updated(string $name): void
    {
        if (in_array($name, ['studentId', 'packageBenefitRuleId', 'subjectId', 'academicLevelId'], true)) {
            $this->refreshPreview();
        }
    }

    public function submit(): void
    {
        $this->validate([
            'studentId' => ['required', 'integer'],
            'packageBenefitRuleId' => ['required', 'string'],
            'subjectId' => ['required', 'string'],
            'academicLevelId' => ['nullable', 'string'],
        ]);

        // Ownership/eligibility is re-verified server-side inside the
        // service regardless of what the client submitted — the picker
        // is already scoped to eligibleStudentsFor(), but the browser
        // state can never be trusted for the actual decision.
        try {
            $proposal = $this->proposals->proposeAndSubmit(new CreatePackageProposalData(
                instructorId: (int) auth()->id(),
                studentId: (int) $this->studentId,
                packageBenefitRuleId: $this->packageBenefitRuleId,
                subjectId: $this->subjectId,
                academicLevelId: $this->academicLevelId !== '' ? $this->academicLevelId : null,
            ));
        } catch (PackageException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        $this->reset(['showForm', 'studentId', 'packageBenefitRuleId', 'subjectId', 'academicLevelId', 'preview', 'previewError']);
        session()->flash('package-proposal-status', sprintf('Package proposal submitted for %s — awaiting admin review.', $proposal->student?->name));
    }

    public function render(): View
    {
        $instructor = auth()->user();

        return view('livewire.frontend.instructor.package-proposal-creator', [
            'eligibleStudents' => $this->proposals->eligibleStudentsFor($instructor),
            'benefitRules' => PackageBenefitRule::query()->active()->orderBy('name')->get(),
            'subjects' => Subject::query()->active()->orderBy('name')->get(),
            'academicLevels' => AcademicLevel::query()->active()->orderBy('name')->get(),
            'proposals' => InstructorPackageProposal::query()
                ->forInstructor((int) $instructor->id)
                ->with(['student', 'packageBenefitRule'])
                ->orderByDesc('created_at')
                ->paginate(10),
        ]);
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function refreshPreview(): void
    {
        $this->preview = null;
        $this->previewError = null;

        if ($this->studentId === '' || $this->packageBenefitRuleId === '' || $this->subjectId === '') {
            return;
        }

        $instructor = auth()->user();
        $student = User::find($this->studentId);
        $rule = PackageBenefitRule::query()->active()->find($this->packageBenefitRuleId);
        $subject = Subject::query()->find($this->subjectId);
        $academicLevel = $this->academicLevelId !== '' ? AcademicLevel::query()->find($this->academicLevelId) : null;

        if ($student === null || $rule === null || $subject === null) {
            return;
        }

        if (! $this->proposals->hasValidRelationship($instructor, $student)) {
            $this->previewError = 'This instructor has no existing paid relationship with this student.';

            return;
        }

        try {
            $price = $this->proposals->previewPrice($student, $instructor, $rule, $subject, $academicLevel);
        } catch (PackageException $e) {
            $this->previewError = $e->getMessage();

            return;
        }

        $this->preview = $this->previewArray($price, $rule);
    }

    /** @return array<string, mixed> */
    private function previewArray(ResolvedPackagePriceData $price, PackageBenefitRule $rule): array
    {
        return [
            'unit_price_minor' => $price->unitPriceMinor,
            'paid_quantity' => $rule->paid_quantity,
            'bonus_quantity' => $rule->bonus_quantity,
            'total_quantity' => $rule->total_quantity,
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
