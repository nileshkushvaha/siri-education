<?php

declare(strict_types=1);

namespace App\Filament\Resources\Academic\RelationManagers;

use App\Curriculum\Exceptions\AcademicContextException;
use App\Curriculum\Services\EducationSystemService;
use App\Models\AcademicLevel;
use App\Models\EducationSystem;
use App\Models\EducationSystemLevel;
use Filament\Actions\Action;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phase 3.1 — manages the exact, student-selectable levels under an
 * Education System (Class 6..12 / Grade 6..12 / Year 6..12, ...). This
 * is a distinct concept from AcademicLevelMappingsRelationManager,
 * which manages the broad internal AcademicLevel band mapping — see
 * EducationSystemLevel model docblock. Mutations always go through
 * EducationSystemService — never a raw Filament attach/detach.
 */
class EducationSystemLevelsRelationManager extends RelationManager
{
    protected static string $relationship = 'levels';

    protected static ?string $title = 'Levels';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('academicLevel'))
            ->recordTitleAttribute('display_label')
            ->columns([
                TextColumn::make('display_label')
                    ->label('Display Label')
                    ->sortable(),
                TextColumn::make('value')
                    ->label('Value'),
                TextColumn::make('normalized_grade')
                    ->label('Normalized Grade')
                    ->placeholder('—'),
                TextColumn::make('academicLevel.name')
                    ->label('Academic Level')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('display_order')
                    ->label('Order')
                    ->sortable(),
            ])
            ->headerActions([
                Action::make('addLevel')
                    ->label('Add Level')
                    ->icon('heroicon-m-plus-circle')
                    ->form(self::levelForm())
                    ->action(function (array $data): void {
                        /** @var EducationSystem $system */
                        $system = $this->getOwnerRecord();

                        try {
                            app(EducationSystemService::class)->addLevel(auth()->user(), $system, $data);
                        } catch (AcademicContextException $e) {
                            Notification::make()->title('Level not added')->body($e->getMessage())->danger()->send();

                            throw new Halt;
                        }

                        Notification::make()->title('Level added')->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Edit')
                    ->icon('heroicon-m-pencil-square')
                    ->form(self::levelForm())
                    ->fillForm(fn (EducationSystemLevel $record): array => [
                        'academic_level_id' => $record->academic_level_id,
                        'value' => $record->value,
                        'display_label' => $record->display_label,
                        'normalized_grade' => $record->normalized_grade,
                        'is_active' => $record->is_active,
                        'display_order' => $record->display_order,
                    ])
                    ->action(function (EducationSystemLevel $record, array $data): void {
                        try {
                            app(EducationSystemService::class)->updateLevel(auth()->user(), $record, $data);
                        } catch (AcademicContextException $e) {
                            Notification::make()->title('Level not updated')->body($e->getMessage())->danger()->send();

                            throw new Halt;
                        }

                        Notification::make()->title('Level updated')->success()->send();
                    }),
                Action::make('remove')
                    ->label('Remove')
                    ->icon('heroicon-m-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (EducationSystemLevel $record): void {
                        app(EducationSystemService::class)->removeLevel(auth()->user(), $record);

                        Notification::make()->title('Level removed')->success()->send();
                    }),
            ])
            ->defaultSort('display_order');
    }

    /** @return array<int, Component> */
    private static function levelForm(): array
    {
        return [
            Select::make('academic_level_id')
                ->label('Academic Level')
                ->options(fn (): array => AcademicLevel::query()->active()->orderBy('name')->pluck('name', 'id')->all())
                ->helperText('The broad internal band this level belongs to (e.g. "Middle School").')
                ->searchable()
                ->required(),
            TextInput::make('value')
                ->label('Value')
                ->maxLength(50)
                ->placeholder('e.g. 10')
                ->helperText('Unique within this education system.')
                ->required(),
            TextInput::make('display_label')
                ->label('Display Label')
                ->maxLength(100)
                ->placeholder('e.g. Class 10')
                ->required(),
            TextInput::make('normalized_grade')
                ->label('Normalized Grade')
                ->numeric()
                ->placeholder('e.g. 10')
                ->helperText('Leave blank for non-numeric levels (e.g. Undergraduate) — currently unsupported for Demo booking.'),
            Toggle::make('is_active')
                ->label('Active')
                ->default(true),
            TextInput::make('display_order')
                ->numeric()
                ->default(0)
                ->minValue(0),
        ];
    }
}
