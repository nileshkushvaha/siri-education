<?php

declare(strict_types=1);

namespace App\Filament\Resources\StudentLearningPlans\Pages;

use App\Enums\InstructorStatus;
use App\Enums\LearningPlanStatus;
use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\StudentLearningPlans\StudentLearningPlanResource;
use App\Filament\Support\Presentation\BackAction;
use App\Models\User;
use App\Services\Student\LearningPlanService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Builder;

class EditStudentLearningPlan extends EditRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = StudentLearningPlanResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to Learning Plans'),
            $this->assignInstructorAction(),
            $this->activatePlanAction(),
            $this->markReviewDueAction(),
            $this->adjustPlanAction(),
            $this->completePlanAction(),
            $this->archivePlanAction(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return [];
    }

    private function assignInstructorAction(): Action
    {
        return Action::make('assignInstructor')
            ->label('Assign Instructor')
            ->icon('heroicon-m-user-plus')
            ->color('info')
            ->visible(fn (): bool => $this->canManagePlan())
            ->form([
                Select::make('primary_instructor_user_id')
                    ->label('Primary instructor')
                    ->options(fn (): array => User::query()
                        ->where('status', User::STATUS_ACTIVE)
                        ->whereHas('roles', fn (Builder $query) => $query->where('name', 'instructor'))
                        ->whereHas('profile', fn (Builder $query) => $query->whereIn('instructor_status', InstructorStatus::bookableValues()))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $data): void {
                $instructor = User::query()->findOrFail($data['primary_instructor_user_id']);

                app(LearningPlanService::class)->assignInstructor(auth()->user(), $this->record, $instructor);
                $this->record->refresh();

                Notification::make()->title('Instructor assigned')->success()->send();
            });
    }

    private function activatePlanAction(): Action
    {
        return Action::make('activatePlan')
            ->label('Activate')
            ->icon('heroicon-m-play-circle')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->canManagePlan() && $this->record->status !== LearningPlanStatus::Active)
            ->action(function (): void {
                app(LearningPlanService::class)->activate(auth()->user(), $this->record);
                $this->record->refresh();

                Notification::make()->title('Learning plan activated')->success()->send();
            });
    }

    private function markReviewDueAction(): Action
    {
        return Action::make('markReviewDue')
            ->label('Mark Review Due')
            ->icon('heroicon-m-flag')
            ->color('warning')
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->canManagePlan() && $this->record->status !== LearningPlanStatus::ReviewDue)
            ->action(function (): void {
                app(LearningPlanService::class)->markReviewDue(auth()->user(), $this->record);
                $this->record->refresh();

                Notification::make()->title('Learning plan marked review due')->success()->send();
            });
    }

    private function adjustPlanAction(): Action
    {
        return Action::make('adjustPlan')
            ->label('Adjust Plan')
            ->icon('heroicon-m-pencil-square')
            ->color('gray')
            ->visible(fn (): bool => $this->canManagePlan())
            ->form([
                TextInput::make('title')
                    ->default(fn (): ?string => $this->record->title)
                    ->maxLength(255),
                Textarea::make('summary')
                    ->default(fn (): ?string => $this->record->summary),
                TextInput::make('recommended_frequency')
                    ->default(fn (): ?string => $this->record->recommended_frequency)
                    ->maxLength(255),
                TextInput::make('recommended_lesson_duration_minutes')
                    ->default(fn (): ?int => $this->record->recommended_lesson_duration_minutes)
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(600),
                Textarea::make('preferred_schedule_note')
                    ->default(fn (): ?string => $this->record->preferred_schedule_note),
                Textarea::make('current_focus')
                    ->default(fn (): ?string => $this->record->current_focus),
                Textarea::make('current_level_note')
                    ->default(fn (): ?string => $this->record->current_level_note),
                DatePicker::make('target_completion_date')
                    ->default(fn (): mixed => $this->record->target_completion_date),
                Textarea::make('reason')
                    ->label('Change reason')
                    ->required()
                    ->maxLength(1000),
            ])
            ->action(function (array $data): void {
                $reason = (string) ($data['reason'] ?? '');
                unset($data['reason']);

                app(LearningPlanService::class)->adjustPlan(auth()->user(), $this->record, $data, $reason);
                $this->record->refresh();

                Notification::make()->title('Learning plan adjusted')->success()->send();
            });
    }

    private function completePlanAction(): Action
    {
        return Action::make('completePlan')
            ->label('Complete')
            ->icon('heroicon-m-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->canManagePlan() && $this->record->status !== LearningPlanStatus::Completed)
            ->action(function (): void {
                app(LearningPlanService::class)->completePlan(auth()->user(), $this->record);
                $this->record->refresh();

                Notification::make()->title('Learning plan completed')->success()->send();
            });
    }

    private function archivePlanAction(): Action
    {
        return Action::make('archivePlan')
            ->label('Archive')
            ->icon('heroicon-m-archive-box')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->canManagePlan() && $this->record->status !== LearningPlanStatus::Archived)
            ->action(function (): void {
                app(LearningPlanService::class)->archivePlan(auth()->user(), $this->record);
                $this->record->refresh();

                Notification::make()->title('Learning plan archived')->success()->send();
            });
    }

    private function canManagePlan(): bool
    {
        return auth()->user()?->can('update', $this->record) ?? false;
    }
}
