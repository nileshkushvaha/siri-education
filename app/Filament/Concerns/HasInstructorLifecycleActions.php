<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Enums\InstructorStatus;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Services\Instructor\InstructorOnboardingService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

/**
 * The instructor lifecycle review actions (Start Review, Approve,
 * Reject, ...), shared between UserResource's EditUser page and
 * InstructorOnboardingResource's dedicated review page — one set of
 * actions, reused everywhere an admin reviews an instructor, never
 * duplicated. Requires the using page to be a Filament EditRecord (for
 * $this->record) whose resource's model is App\Models\User.
 */
trait HasInstructorLifecycleActions
{
    /** @return array<int, Action> */
    protected function instructorLifecycleActions(): array
    {
        return [
            $this->markInstructorUnderReviewAction(),
            $this->markInstructorInterviewRequiredAction(),
            $this->requestInstructorDocumentsAction(),
            $this->approveInstructorAction(),
            $this->rejectInstructorAction(),
            $this->forceApproveInstructorAction(),
            $this->activateInstructorAction(),
            $this->startInstructorVacationAction(),
            $this->resumeInstructorFromVacationAction(),
            $this->suspendInstructorAction(),
            $this->archiveInstructorAction(),
        ];
    }

    private function markInstructorUnderReviewAction(): Action
    {
        return Action::make('markInstructorUnderReview')
            ->label('Start Review')
            ->icon('heroicon-m-eye')
            ->color('info')
            ->modalHeading('Start instructor review?')
            ->modalDescription('This records that an administrator has begun reviewing the submitted application.')
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->canReviewInstructor()
                && $this->record->profile?->instructor_status === InstructorStatus::Submitted
            )
            ->action(function (): void {
                app(InstructorOnboardingService::class)->markUnderReview(auth()->user(), $this->record);

                Notification::make()
                    ->title('Instructor marked under review')
                    ->success()
                    ->send();
            });
    }

    private function requestInstructorDocumentsAction(): Action
    {
        return Action::make('requestInstructorDocuments')
            ->label('Request Documents')
            ->icon('heroicon-m-document-plus')
            ->color('warning')
            ->visible(fn (): bool => $this->canReviewInstructor()
                && in_array($this->record->profile?->instructor_status, [InstructorStatus::Submitted, InstructorStatus::UnderReview, InstructorStatus::InterviewRequired], true)
            )
            ->form([
                Textarea::make('reason')
                    ->label('What evidence is required?')
                    ->required()
                    ->placeholder('List the missing, unreadable, expired, or inconsistent documents and explain what the instructor should provide.')
                    ->helperText('This message becomes part of the application record and should be specific and actionable.')
                    ->maxLength(1000),
            ])
            ->action(function (array $data): void {
                app(InstructorOnboardingService::class)->requestDocuments(auth()->user(), $this->record, $data['reason']);

                Notification::make()
                    ->title('Documents requested')
                    ->success()
                    ->send();
            });
    }

    private function approveInstructorAction(): Action
    {
        return Action::make('approveInstructor')
            ->label('Approve')
            ->icon('heroicon-m-check-badge')
            ->color('success')
            ->modalHeading('Approve this instructor?')
            ->modalDescription('Approval makes the instructor eligible for public discovery and bookings according to profile visibility and availability.')
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->canReviewInstructor()
                && ! in_array($this->record->profile?->instructor_status, InstructorStatus::bookable(), true)
            )
            ->form([
                Textarea::make('reason')
                    ->label('Internal approval note')
                    ->placeholder('Optional: record the evidence checked or any operational follow-up required.')
                    ->maxLength(500),
            ])
            ->action(function (array $data): void {
                app(InstructorOnboardingService::class)->approve(auth()->user(), $this->record, $data['reason'] ?? null);

                Notification::make()
                    ->title('Instructor approved')
                    ->success()
                    ->send();
            });
    }

    private function rejectInstructorAction(): Action
    {
        return Action::make('rejectInstructor')
            ->label('Reject')
            ->icon('heroicon-m-x-circle')
            ->color('danger')
            ->visible(fn (): bool => $this->canReviewInstructor()
                && in_array($this->record->profile?->instructor_status, InstructorStatus::needsReview(), true)
            )
            ->form([
                Textarea::make('reason')
                    ->label('Rejection reason')
                    ->required()
                    ->placeholder('Explain the specific eligibility, evidence, safety, or quality requirement that was not met.')
                    ->helperText('Use clear, professional language suitable for the permanent review record.')
                    ->maxLength(1000),
            ])
            ->action(function (array $data): void {
                app(InstructorOnboardingService::class)->reject(auth()->user(), $this->record, $data['reason']);

                Notification::make()
                    ->title('Instructor rejected')
                    ->success()
                    ->send();
            });
    }

    private function canReviewInstructor(): bool
    {
        $admin = auth()->user();

        return $this->record->hasRole('instructor')
            && $admin instanceof User
            && app(InstructorOnboardingService::class)->canReviewApplications($admin);
    }

    /** A Filament panel session is always a User, but stay defensive to match canReviewInstructor()'s existing pattern. */
    private function actingAdmin(): ?User
    {
        $admin = auth()->user();

        return $admin instanceof User ? $admin : null;
    }

    /**
     * Admin override — bypasses the normal instructor lifecycle (draft →
     * submitted → under_review → ... → approved) to force an instructor
     * straight to Approved. Requires a reason, logged via
     * AuditTrailService::logOverride() (event 'admin_override', findable
     * independently of the 'instructor' log this also feeds into via
     * UserProfileObserver).
     */
    private function forceApproveInstructorAction(): Action
    {
        return Action::make('forceApproveInstructor')
            ->label('Force Approve')
            ->icon('heroicon-m-shield-check')
            ->color('warning')
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->canReviewInstructor()
                && ! in_array($this->record->profile?->instructor_status, InstructorStatus::bookable(), true)
            )
            ->form([
                Textarea::make('reason')
                    ->label('Reason for override')
                    ->required()
                    ->maxLength(500)
                    ->helperText('Why is this instructor being approved outside the normal review flow?'),
            ])
            ->action(function (array $data): void {
                $admin = auth()->user();
                if (! $admin instanceof User) {
                    return;
                }

                $previousStatus = $this->record->profile?->instructor_status;

                $this->record->profile()->update(['instructor_status' => InstructorStatus::Approved]);

                app(AuditTrailService::class)->logOverride(
                    admin: $admin,
                    logName: 'instructor',
                    event: 'admin_override',
                    description: "Instructor status force-approved for {$this->record->name}",
                    reason: $data['reason'],
                    subject: $this->record,
                    properties: [
                        'previous_status' => $previousStatus?->value,
                        'new_status' => InstructorStatus::Approved->value,
                    ],
                );

                Notification::make()
                    ->title('Instructor force-approved')
                    ->success()
                    ->send();
            });
    }

    private function markInstructorInterviewRequiredAction(): Action
    {
        return Action::make('markInstructorInterviewRequired')
            ->label('Mark Interview Required')
            ->icon('heroicon-m-video-camera')
            ->color('info')
            ->visible(fn (): bool => ($admin = $this->actingAdmin()) !== null
                && app(InstructorOnboardingService::class)->canRequestInterview($admin)
                && $this->record->profile?->instructor_status === InstructorStatus::UnderReview
            )
            ->form([
                Textarea::make('reason')
                    ->label('Interview reason')
                    ->required()
                    ->maxLength(500)
                    ->placeholder('e.g. Technical interview required before approval.'),
            ])
            ->action(function (array $data): void {
                if (($admin = $this->actingAdmin()) === null) {
                    return;
                }

                app(InstructorOnboardingService::class)->markInterviewRequired($this->record, $admin, $data['reason']);

                Notification::make()
                    ->title('Instructor marked interview required')
                    ->success()
                    ->send();
            });
    }

    private function activateInstructorAction(): Action
    {
        return Action::make('activateInstructor')
            ->label('Activate')
            ->icon('heroicon-m-bolt')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (): bool => ($admin = $this->actingAdmin()) !== null
                && app(InstructorOnboardingService::class)->canActivate($admin)
                && $this->record->profile?->instructor_status === InstructorStatus::Approved
            )
            ->action(function (): void {
                if (($admin = $this->actingAdmin()) === null) {
                    return;
                }

                app(InstructorOnboardingService::class)->activate($this->record, $admin);

                Notification::make()
                    ->title('Instructor activated')
                    ->success()
                    ->send();
            });
    }

    private function startInstructorVacationAction(): Action
    {
        return Action::make('startInstructorVacation')
            ->label('Start Vacation')
            ->icon('heroicon-m-sun')
            ->color('warning')
            ->requiresConfirmation()
            ->visible(fn (): bool => ($admin = $this->actingAdmin()) !== null
                && app(InstructorOnboardingService::class)->canManageVacation($admin)
                && $this->record->profile?->instructor_status === InstructorStatus::Active
            )
            ->action(function (): void {
                if (($admin = $this->actingAdmin()) === null) {
                    return;
                }

                app(InstructorOnboardingService::class)->setVacation($this->record, $admin);

                Notification::make()
                    ->title('Instructor vacation started')
                    ->success()
                    ->send();
            });
    }

    private function resumeInstructorFromVacationAction(): Action
    {
        return Action::make('resumeInstructorFromVacation')
            ->label('Resume')
            ->icon('heroicon-m-play')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (): bool => ($admin = $this->actingAdmin()) !== null
                && app(InstructorOnboardingService::class)->canManageVacation($admin)
                && $this->record->profile?->instructor_status === InstructorStatus::Vacation
            )
            ->action(function (): void {
                if (($admin = $this->actingAdmin()) === null) {
                    return;
                }

                app(InstructorOnboardingService::class)->resumeFromVacation($this->record, $admin);

                Notification::make()
                    ->title('Instructor resumed from vacation')
                    ->success()
                    ->send();
            });
    }

    private function suspendInstructorAction(): Action
    {
        return Action::make('suspendInstructor')
            ->label('Suspend')
            ->icon('heroicon-m-no-symbol')
            ->color('danger')
            ->visible(fn (): bool => ($admin = $this->actingAdmin()) !== null
                && app(InstructorOnboardingService::class)->canSuspend($admin)
                && in_array($this->record->profile?->instructor_status, [
                    InstructorStatus::Active,
                    InstructorStatus::Vacation,
                    InstructorStatus::Approved,
                ], true)
            )
            ->form([
                Textarea::make('reason')
                    ->label('Suspension reason')
                    ->required()
                    ->maxLength(1000),
            ])
            ->action(function (array $data): void {
                if (($admin = $this->actingAdmin()) === null) {
                    return;
                }

                app(InstructorOnboardingService::class)->suspend($this->record, $admin, $data['reason']);

                Notification::make()
                    ->title('Instructor suspended')
                    ->success()
                    ->send();
            });
    }

    private function archiveInstructorAction(): Action
    {
        return Action::make('archiveInstructor')
            ->label('Archive')
            ->icon('heroicon-m-archive-box')
            ->color('gray')
            ->requiresConfirmation()
            ->visible(fn (): bool => ($admin = $this->actingAdmin()) !== null
                && app(InstructorOnboardingService::class)->canArchive($admin)
                && in_array($this->record->profile?->instructor_status, [
                    InstructorStatus::Suspended,
                    InstructorStatus::Vacation,
                ], true)
            )
            ->form([
                Textarea::make('reason')
                    ->label('Archive reason')
                    ->maxLength(1000),
            ])
            ->action(function (array $data): void {
                if (($admin = $this->actingAdmin()) === null) {
                    return;
                }

                app(InstructorOnboardingService::class)->archive($this->record, $admin, $data['reason'] ?? null);

                Notification::make()
                    ->title('Instructor archived')
                    ->success()
                    ->send();
            });
    }
}
