<?php

declare(strict_types=1);

namespace App\Filament\Resources\Academic\RelationManagers;

use App\Curriculum\Exceptions\AcademicContextException;
use App\Curriculum\Services\EducationSystemService;
use App\Models\AcademicLevel;
use App\Models\EducationSystem;
use App\Models\EducationSystemAcademicLevel;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Mutations always go through EducationSystemService — never a raw Filament attach/detach. */
class AcademicLevelMappingsRelationManager extends RelationManager
{
    protected static string $relationship = 'academicLevelMappings';

    protected static ?string $title = 'Academic Levels';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('academicLevel'))
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('academicLevel.name')
                    ->label('Academic Level')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('display_order')
                    ->label('Order')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                Action::make('addAcademicLevel')
                    ->label('Add Academic Level')
                    ->icon('heroicon-m-plus-circle')
                    ->form([
                        Select::make('academic_level_id')
                            ->label('Academic Level')
                            ->options(fn (): array => AcademicLevel::query()->active()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required(),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                        TextInput::make('display_order')
                            ->numeric()
                            ->default(0)
                            ->minValue(0),
                    ])
                    ->action(function (array $data): void {
                        /** @var EducationSystem $system */
                        $system = $this->getOwnerRecord();
                        $level = AcademicLevel::query()->findOrFail($data['academic_level_id']);

                        try {
                            app(EducationSystemService::class)->mapToAcademicLevel(auth()->user(), $system, $level, (bool) $data['is_active'], (int) $data['display_order']);
                        } catch (AcademicContextException $e) {
                            Notification::make()->title('Mapping not added')->body($e->getMessage())->danger()->send();

                            throw new Halt;
                        }

                        Notification::make()->title('Academic level mapped')->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('remove')
                    ->label('Remove')
                    ->icon('heroicon-m-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (EducationSystemAcademicLevel $record): void {
                        app(EducationSystemService::class)->unmapFromAcademicLevel(auth()->user(), $record);

                        Notification::make()->title('Academic level unmapped')->success()->send();
                    }),
            ])
            ->defaultSort('display_order');
    }
}
