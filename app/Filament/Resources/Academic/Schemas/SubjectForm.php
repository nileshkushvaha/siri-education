<?php

namespace App\Filament\Resources\Academic\Schemas;

use App\Actions\GeneratePageSlugAction;
use App\Enums\AcademicStatus;
use App\Models\Subject;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SubjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Subject Information')
                    ->columnSpanFull()
                    ->schema([
                        // Identity — category first (a subject always belongs to
                        // one), then name, which drives the auto-generated slug.
                        Grid::make(2)->schema([
                            Select::make('academic_category_id')
                                ->label('Category')
                                ->relationship('category', 'name')
                                ->searchable()
                                ->preload()
                                ->required()
                                ->placeholder('Select a category'),
                            TextInput::make('name')
                                ->required()
                                ->maxLength(150)
                                ->placeholder('e.g. Algebra')
                                ->live(onBlur: true)
                                ->afterStateUpdated(function ($state, $set, string $operation): void {
                                    if ($operation !== 'create' || blank($state)) {
                                        return;
                                    }

                                    $set('slug', app(GeneratePageSlugAction::class)->execute($state, null, Subject::class));
                                }),
                        ]),
                        Grid::make(2)->schema([
                            // Technical identifier: generated once, always hidden,
                            // and deliberately stable when the subject is renamed.
                            TextInput::make('slug')
                                ->required()
                                ->unique('subjects', 'slug', ignoreRecord: true)
                                ->maxLength(150)
                                ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                                ->placeholder('e.g. algebra')
                                ->helperText('Changing this changes the public URL.')
                                ->hidden()
                                ->disabledOn('edit')
                                ->dehydrated(fn (string $operation): bool => $operation === 'create')
                                ->dehydratedWhenHidden()
                                ->dehydrateStateUsing(fn (?string $state, $get): string => filled($state)
                                    ? $state
                                    : app(GeneratePageSlugAction::class)->execute((string) $get('name'), null, Subject::class)),
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

                        // Content — long-form text always gets the full row.
                        Textarea::make('description')
                            ->rows(3)
                            ->maxLength(1000)
                            ->placeholder('Brief overview of this subject…')
                            ->columnSpanFull(),

                        // Display settings.
                        Grid::make(2)->schema([
                            Select::make('countries')
                                ->label('Available In Countries')
                                ->relationship('countries', 'name')
                                ->multiple()
                                ->searchable()
                                ->preload()
                                ->placeholder('Select countries (leave empty for all)')
                                ->helperText('Leave empty to make this subject available in every country.'),
                        ]),
                    ]),
            ]);
    }
}
