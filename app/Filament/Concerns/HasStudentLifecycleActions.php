<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Enums\StudentStatus;
use App\Models\User;
use App\Services\Student\StudentLifecycleService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Governed student_status
 * transitions on the User resource's Edit page (SRS-2-20/SRS-B1-12). Mirrors
 * HasInstructorLifecycleActions' exact pattern (permission-gated
 * visible(), required-reason Textarea for adverse/administrative
 * transitions, delegation to the lifecycle service, safe notification).
 * All writes go through StudentLifecycleService — never a raw
 * student_status update — so this trait can never bypass the governed
 * transition matrix or its concurrency-safe row lock.
 */
trait HasStudentLifecycleActions
{
    /** @return array<int, Action> */
    protected function studentLifecycleActions(): array
    {
        return [
            $this->activateStudentAction(),
            $this->suspendStudentAction(),
            $this->reactivateStudentAction(),
            $this->archiveStudentAction(),
        ];
    }

    private function isStudentRecord(): bool
    {
        return $this->record->hasRole('student');
    }

    private function currentStudentStatus(): ?StudentStatus
    {
        return $this->record->profile?->student_status;
    }

    private function actingAdminForStudentLifecycle(): ?User
    {
        $admin = auth()->user();

        return $admin instanceof User ? $admin : null;
    }

    private function activateStudentAction(): Action
    {
        return Action::make('activateStudent')
            ->label('Activate')
            ->icon('heroicon-m-bolt')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('Activates this student account outside the normal email-verification trigger.')
            ->visible(fn (): bool => $this->isStudentRecord()
                && ($admin = $this->actingAdminForStudentLifecycle()) !== null
                && app(StudentLifecycleService::class)->canActivate($admin)
                && $this->currentStudentStatus() === StudentStatus::Registered
            )
            ->action(function (): void {
                if (($admin = $this->actingAdminForStudentLifecycle()) === null) {
                    return;
                }

                $this->runStudentTransition(fn () => app(StudentLifecycleService::class)->activate($this->record, $admin), 'Student activated');
            });
    }

    private function suspendStudentAction(): Action
    {
        return Action::make('suspendStudent')
            ->label('Suspend')
            ->icon('heroicon-m-no-symbol')
            ->color('danger')
            ->visible(fn (): bool => $this->isStudentRecord()
                && ($admin = $this->actingAdminForStudentLifecycle()) !== null
                && app(StudentLifecycleService::class)->canSuspend($admin)
                && in_array($this->currentStudentStatus(), [StudentStatus::Registered, StudentStatus::Active], true)
            )
            ->form([
                Textarea::make('reason')
                    ->label('Suspension reason')
                    ->required()
                    ->maxLength(1000),
            ])
            ->action(function (array $data): void {
                if (($admin = $this->actingAdminForStudentLifecycle()) === null) {
                    return;
                }

                $this->runStudentTransition(
                    fn () => app(StudentLifecycleService::class)->suspend($this->record, $admin, $data['reason']),
                    'Student suspended',
                );
            });
    }

    private function reactivateStudentAction(): Action
    {
        return Action::make('reactivateStudent')
            ->label('Reactivate')
            ->icon('heroicon-m-play')
            ->color('success')
            ->visible(fn (): bool => $this->isStudentRecord()
                && ($admin = $this->actingAdminForStudentLifecycle()) !== null
                && app(StudentLifecycleService::class)->canReactivate($admin)
                && $this->currentStudentStatus() === StudentStatus::Suspended
            )
            ->form([
                Textarea::make('reason')
                    ->label('Reactivation reason')
                    ->required()
                    ->maxLength(1000),
            ])
            ->modalDescription('The student will need to log in again — any previously active session is not restored.')
            ->action(function (array $data): void {
                if (($admin = $this->actingAdminForStudentLifecycle()) === null) {
                    return;
                }

                $this->runStudentTransition(
                    fn () => app(StudentLifecycleService::class)->reactivate($this->record, $admin, $data['reason']),
                    'Student reactivated',
                );
            });
    }

    private function archiveStudentAction(): Action
    {
        return Action::make('archiveStudent')
            ->label('Archive')
            ->icon('heroicon-m-archive-box')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription('Archiving preserves all profile, booking, payment, wallet, and review history — it does not delete any data. This cannot be undone from this page.')
            ->visible(fn (): bool => $this->isStudentRecord()
                && ($admin = $this->actingAdminForStudentLifecycle()) !== null
                && app(StudentLifecycleService::class)->canArchive($admin)
                && in_array($this->currentStudentStatus(), [StudentStatus::Registered, StudentStatus::Active, StudentStatus::Suspended], true)
            )
            ->form([
                Textarea::make('reason')
                    ->label('Archive reason')
                    ->maxLength(1000),
            ])
            ->action(function (array $data): void {
                if (($admin = $this->actingAdminForStudentLifecycle()) === null) {
                    return;
                }

                $this->runStudentTransition(
                    fn () => app(StudentLifecycleService::class)->archive($this->record, $admin, $data['reason'] ?? null),
                    'Student archived',
                );
            });
    }

    /**
     * Shared success/failure handling — a stale form re-submitted after
     * the status already changed (e.g. two admin tabs open) hits
     * StudentLifecycleService's row-locked ValidationException here,
     * surfaced as a safe failure notification instead of a raw 500.
     */
    private function runStudentTransition(\Closure $transition, string $successTitle): void
    {
        try {
            $transition();
        } catch (ValidationException $e) {
            Notification::make()
                ->title('Transition failed')
                ->body(collect($e->errors())->flatten()->implode(' '))
                ->danger()
                ->send();

            return;
        }

        Notification::make()->title($successTitle)->success()->send();
    }
}
