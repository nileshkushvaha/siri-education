<?php

declare(strict_types=1);

namespace App\Filament\Resources\Academic\RelationManagers;

use App\Curriculum\Exceptions\AcademicContextException;
use App\Curriculum\Services\EducationSystemService;
use App\Models\Curriculum;
use App\Models\CurriculumEducationSystem;
use App\Models\EducationSystem;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Extends the existing Curriculum admin UI rather than duplicating
 * curriculum editing — this only manages which Education Systems a
 * Curriculum applies to. Structural curriculum editing remains
 * CurriculumResource's job exclusively.
 */
class CurriculumMappingsRelationManager extends RelationManager
{
    protected static string $relationship = 'curriculumMappings';

    protected static ?string $title = 'Curricula';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['curriculum.subject', 'curriculum.academicLevel']))
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('curriculum.name')
                    ->label('Curriculum')
                    ->sortable(),
                TextColumn::make('curriculum.subject.name')
                    ->label('Subject'),
                TextColumn::make('curriculum.academicLevel.name')
                    ->label('Academic Level'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                Action::make('addCurriculum')
                    ->label('Add Curriculum')
                    ->icon('heroicon-m-plus-circle')
                    ->form([
                        Select::make('curriculum_id')
                            ->label('Curriculum')
                            ->options(fn (): array => Curriculum::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        /** @var EducationSystem $system */
                        $system = $this->getOwnerRecord();
                        $curriculum = Curriculum::query()->findOrFail($data['curriculum_id']);

                        try {
                            app(EducationSystemService::class)->mapToCurriculum(auth()->user(), $system, $curriculum);
                        } catch (AcademicContextException $e) {
                            Notification::make()->title('Mapping not added')->body($e->getMessage())->danger()->send();

                            throw new Halt;
                        }

                        Notification::make()->title('Curriculum mapped')->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('remove')
                    ->label('Remove')
                    ->icon('heroicon-m-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (CurriculumEducationSystem $record): void {
                        app(EducationSystemService::class)->unmapFromCurriculum(auth()->user(), $record);

                        Notification::make()->title('Curriculum unmapped')->success()->send();
                    }),
            ]);
    }
}
