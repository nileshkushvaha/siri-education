<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Instructor;

use App\Enums\InstructorStatus;
use App\Services\Instructor\AvailabilityChangeImpactService;
use App\Services\Instructor\InstructorOnboardingService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Self-service vacation toggle. The instructor always acts
 * on their own account — $instructor and $actor are both auth()->user(),
 * never a client-supplied id — so InstructorOnboardingService's
 * self-service authorization path is the entire authorization boundary
 * here; no separate policy/gate is needed. All business rules (allowed
 * transitions, audit trail, row-locked concurrency guard) stay owned by
 * InstructorOnboardingService::setVacation()/resumeFromVacation().
 */
final class VacationModeManager extends Component
{
    public bool $confirmingEnable = false;

    /** Informational: how many confirmed upcoming lessons the instructor still owes; shown in the enable-confirmation panel. Vacation never strands these — it only pauses NEW bookings. */
    public int $upcomingConfirmedLessons = 0;

    public bool $confirmingResume = false;

    public function mount(): void
    {
        abort_unless(auth()->user()?->hasRole('instructor'), 403);
        abort_if(auth()->user()->profile?->instructor_status === InstructorStatus::Archived, 403);
    }

    public function confirmEnable(): void
    {
        $this->upcomingConfirmedLessons = app(AvailabilityChangeImpactService::class)
            ->upcomingConfirmedCount(auth()->user());
        $this->confirmingEnable = true;
    }

    public function cancelEnable(): void
    {
        $this->confirmingEnable = false;
    }

    public function enableVacation(InstructorOnboardingService $onboarding): void
    {
        $user = auth()->user();

        try {
            $onboarding->setVacation($user, $user);
        } catch (ValidationException $e) {
            $this->confirmingEnable = false;
            $this->addError('form', 'Unable to enable vacation mode right now — please refresh and try again.');

            return;
        }

        $this->confirmingEnable = false;
        session()->flash('vacation-status', 'Vacation mode enabled. New students cannot book lessons with you until you resume.');
    }

    public function confirmResume(): void
    {
        $this->confirmingResume = true;
    }

    public function cancelResume(): void
    {
        $this->confirmingResume = false;
    }

    public function resumeTeaching(InstructorOnboardingService $onboarding): void
    {
        $user = auth()->user();

        try {
            $onboarding->resumeFromVacation($user, $user);
        } catch (ValidationException $e) {
            $this->confirmingResume = false;
            $this->addError('form', 'Unable to resume teaching right now — please refresh and try again.');

            return;
        }

        $this->confirmingResume = false;
        session()->flash('vacation-status', 'Welcome back! You are accepting new bookings again.');
    }

    public function render(): View
    {
        $profile = auth()->user()->profile;

        return view('livewire.frontend.instructor.vacation-mode-manager', [
            'status' => $profile?->instructor_status,
            'statusChangedAt' => $profile?->instructor_reviewed_at,
        ]);
    }
}
