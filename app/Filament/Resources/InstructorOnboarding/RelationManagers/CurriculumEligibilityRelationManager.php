<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorOnboarding\RelationManagers;

use App\Curriculum\Exceptions\InstructorAcademicEligibilityException;
use App\Curriculum\Services\InstructorAcademicEligibilityService;
use App\Models\Curriculum;
use App\Models\EducationSystem;
use App\Models\InstructorCurriculumEligibility;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

/**
 * Admin → Instructor → Academic Capabilities → Education Systems/
 * Curricula, hung off the existing Instructor Onboarding review page
 * (InstructorOnboardingResource) rather than a new top-level
 * navigation item. Subject and Academic Level are always derived from
 * the selected Curriculum — never asked for separately. All mutations
 * route through InstructorAcademicEligibilityService exclusively
 * (mirrors CurriculumMappingsRelationManager), so duplicate prevention
 * and Subject/Level validation cannot be bypassed by this UI.
 */
class CurriculumEligibilityRelationManager extends RelationManager
{
    protected static string $relationship = 'instructorCurriculumEligibilities';

    protected static ?string $title = 'Academic Capabilities';

    protected static string|\BackedEnum|null $icon = Heroicon::OutlinedAcademicCap;

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['educationSystem', 'curriculum.subject', 'curriculum.academicLevel']))
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('educationSystem.name')
                    ->label('Education System')
                    ->sortable(),
                TextColumn::make('curriculum.name')
                    ->label('Curriculum')
                    ->sortable(),
                TextColumn::make('curriculum.subject.name')
                    ->label('Subject'),
                TextColumn::make('curriculum.academicLevel.name')
                    ->label('Academic Level'),
                TextColumn::make('published_version')
                    ->label('Published Version')
                    ->state(fn (InstructorCurriculumEligibility $record): string => $record->curriculum?->latestPublishedVersion()?->version_number
                        ? 'v'.$record->curriculum->latestPublishedVersion()->version_number
                        : 'None (not currently bookable)')
                    ->badge()
                    ->color(fn (InstructorCurriculumEligibility $record): string => $record->curriculum?->latestPublishedVersion() ? 'success' : 'warning'),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('approved_at')
                    ->label('Approved')
                    ->dateTime()
                    ->placeholder('—'),
            ])
            ->headerActions([
                Action::make('addEligibility')
                    ->label('Add Eligibility')
                    ->icon('heroicon-m-plus-circle')
                    ->form([
                        Select::make('education_system_id')
                            ->label('Education System')
                            ->options(fn (): array => EducationSystem::query()->active()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn ($set) => $set('curriculum_id', null)),
                        Select::make('curriculum_id')
                            ->label('Curriculum')
                            ->options(function ($get): array {
                                $systemId = $get('education_system_id');

                                if (! $systemId) {
                                    return [];
                                }

                                /** @var EducationSystem|null $system */
                                $system = EducationSystem::query()->find($systemId);

                                if ($system === null) {
                                    return [];
                                }

                                return $this->eligibleCurriculumOptions($system);
                            })
                            ->searchable()
                            ->required()
                            ->helperText('Only curricula matching this system, and this instructor\'s existing Subject/Level capability, are listed.'),
                        Textarea::make('notes')
                            ->label('Admin notes')
                            ->rows(2),
                    ])
                    ->action(function (array $data): void {
                        /** @var User $instructor */
                        $instructor = $this->getOwnerRecord();
                        $system = EducationSystem::query()->findOrFail($data['education_system_id']);
                        $curriculum = Curriculum::query()->findOrFail($data['curriculum_id']);

                        try {
                            app(InstructorAcademicEligibilityService::class)->assign(
                                auth()->user(),
                                $instructor,
                                $system,
                                $curriculum,
                                $data['notes'] ?? null,
                            );
                        } catch (InstructorAcademicEligibilityException $e) {
                            Notification::make()->title('Eligibility not added')->body($e->getMessage())->danger()->send();

                            throw new Halt;
                        }

                        Notification::make()->title('Eligibility added')->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('deactivate')
                    ->label('Deactivate')
                    ->icon('heroicon-m-x-circle')
                    ->color('danger')
                    ->visible(fn (InstructorCurriculumEligibility $record): bool => $record->is_active)
                    ->requiresConfirmation()
                    ->action(function (InstructorCurriculumEligibility $record): void {
                        app(InstructorAcademicEligibilityService::class)->deactivate(auth()->user(), $record);

                        Notification::make()->title('Eligibility deactivated')->success()->send();
                    }),
                Action::make('reactivate')
                    ->label('Reactivate')
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->visible(fn (InstructorCurriculumEligibility $record): bool => ! $record->is_active)
                    ->requiresConfirmation()
                    ->action(function (InstructorCurriculumEligibility $record): void {
                        try {
                            app(InstructorAcademicEligibilityService::class)->reactivate(auth()->user(), $record);
                        } catch (InstructorAcademicEligibilityException $e) {
                            Notification::make()->title('Could not reactivate')->body($e->getMessage())->danger()->send();

                            throw new Halt;
                        }

                        Notification::make()->title('Eligibility reactivated')->success()->send();
                    }),
            ]);
    }

    /**
     * UI convenience filtering only (spec: "UI filtering is convenience;
     * domain validation is authority") — narrows the Curriculum select
     * to options InstructorAcademicEligibilityService::validateConfiguration()
     * would actually accept, so the admin isn't shown choices that will
     * just fail on submit. The service call remains the sole authority.
     */
    private function eligibleCurriculumOptions(EducationSystem $system): array
    {
        /** @var User $instructor */
        $instructor = $this->getOwnerRecord();
        $service = app(InstructorAcademicEligibilityService::class);

        return Curriculum::query()
            ->with(['subject', 'academicLevel'])
            ->get()
            ->filter(function (Curriculum $curriculum) use ($service, $instructor, $system): bool {
                try {
                    $service->validateConfiguration($instructor, $system, $curriculum);

                    return true;
                } catch (InstructorAcademicEligibilityException) {
                    return false;
                }
            })
            ->sortBy('name')
            ->pipe(fn (Collection $curricula): array => $curricula
                ->mapWithKeys(fn (Curriculum $c): array => [
                    $c->id => sprintf('%s — %s (%s)', $c->name, $c->subject?->name, $c->academicLevel?->name),
                ])
                ->all());
    }
}
