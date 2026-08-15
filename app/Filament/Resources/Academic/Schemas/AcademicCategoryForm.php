<?php

namespace App\Filament\Resources\Academic\Schemas;

use App\Actions\GeneratePageSlugAction;
use App\Models\AcademicCategory;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AcademicCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Category Information')
                    ->columnSpanFull()
                    ->schema([
                        // Slugs are technical identifiers: generate once during
                        // creation, keep them hidden, and preserve them on rename.
                        Grid::make(2)->schema([
                            TextInput::make('name')
                                ->required()
                                ->maxLength(255)
                                ->placeholder('e.g. Mathematics')
                                ->live(onBlur: true)
                                ->afterStateUpdated(function ($state, $set, string $operation): void {
                                    if ($operation !== 'create' || blank($state)) {
                                        return;
                                    }

                                    $set('slug', app(GeneratePageSlugAction::class)->execute($state, null, AcademicCategory::class));
                                }),
                            TextInput::make('slug')
                                ->required()
                                ->unique('academic_categories', 'slug', ignoreRecord: true)
                                ->maxLength(255)
                                ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                                ->placeholder('e.g. mathematics')
                                ->helperText('Changing this changes the public URL.')
                                ->hidden()
                                ->disabledOn('edit')
                                ->dehydrated(fn (string $operation): bool => $operation === 'create')
                                ->dehydratedWhenHidden()
                                ->dehydrateStateUsing(fn (?string $state, $get): string => filled($state)
                                    ? $state
                                    : app(GeneratePageSlugAction::class)->execute((string) $get('name'), null, AcademicCategory::class)),
                        ]),

                        // Content — long-form text always gets the full row.
                        Textarea::make('description')
                            ->rows(3)
                            ->maxLength(1000)
                            ->placeholder('Brief overview of this category…')
                            ->columnSpanFull(),

                        // Display settings.
                        Grid::make(2)->schema([
                            TextInput::make('icon')
                                ->maxLength(100)
                                ->placeholder('e.g. heroicon-o-academic-cap')
                                ->helperText('Optional Heroicon name for display on the frontend.'),
                        ]),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ]),
            ]);
    }
}
