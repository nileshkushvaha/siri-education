<?php

declare(strict_types=1);

namespace App\Filament\Resources\Academic\Schemas;

use App\Actions\GeneratePageSlugAction;
use App\Enums\AcademicStatus;
use App\Models\EducationSystem;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EducationSystemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Education System')
                ->columnSpanFull()
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(150)
                            ->placeholder('e.g. CBSE, IB, GCSE')
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, $set, string $operation): void {
                                if ($operation === 'create' && filled($state)) {
                                    $set('slug', app(GeneratePageSlugAction::class)->execute($state, null, EducationSystem::class));
                                }
                            }),
                        TextInput::make('slug')
                            ->required()
                            ->maxLength(150)
                            ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                            ->hidden()
                            ->disabledOn('edit')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create')
                            ->dehydratedWhenHidden()
                            ->dehydrateStateUsing(fn (?string $state, $get): string => filled($state)
                                ? $state
                                : app(GeneratePageSlugAction::class)->execute((string) $get('name'), null, EducationSystem::class)),
                    ]),
                    Grid::make(2)->schema([
                        TextInput::make('code')
                            ->maxLength(30)
                            ->placeholder('e.g. CBSE')
                            ->helperText('Optional short code shown in compact UI.'),
                        Select::make('status')
                            ->options(
                                collect(AcademicStatus::cases())
                                    ->mapWithKeys(fn (AcademicStatus $s) => [$s->value => $s->label()])
                                    ->toArray()
                            )
                            ->default(AcademicStatus::Active->value)
                            ->required()
                            ->placeholder('Select status'),
                    ]),
                    Textarea::make('description')
                        ->rows(3)
                        ->maxLength(2000)
                        ->columnSpanFull()
                        ->placeholder('Brief overview of this education system…'),
                    // Phase 3.1 — what the country-aware booking wizard
                    // calls the level a student picks under this system
                    // (Class 6.., Grade 6.., Year 6..). Never hardcoded
                    // in PHP/Blade; blank falls back to "Level"/"Levels".
                    Grid::make(2)->schema([
                        TextInput::make('level_term_singular')
                            ->label('Level term (singular)')
                            ->maxLength(30)
                            ->placeholder('e.g. Class, Grade, Year')
                            ->helperText('Shown as "Choose a Class" in the booking wizard. Falls back to "Level" when blank.'),
                        TextInput::make('level_term_plural')
                            ->label('Level term (plural)')
                            ->maxLength(30)
                            ->placeholder('e.g. Classes, Grades, Years')
                            ->helperText('Falls back to "Levels" when blank.'),
                    ]),
                ]),
        ]);
    }
}
