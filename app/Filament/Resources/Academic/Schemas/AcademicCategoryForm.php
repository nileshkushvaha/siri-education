<?php

namespace App\Filament\Resources\Academic\Schemas;

use App\Actions\GeneratePageSlugAction;
use App\Models\AcademicCategory;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AcademicCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Category Information')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, $set): void {
                                if (blank($state)) {
                                    return;
                                }

                                $set('slug', app(GeneratePageSlugAction::class)->execute($state, null, AcademicCategory::class));
                            }),
                        TextInput::make('slug')
                            ->required()
                            ->unique('academic_categories', 'slug', ignoreRecord: true)
                            ->maxLength(255)
                            ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/'),
                        Textarea::make('description')
                            ->rows(3)
                            ->maxLength(1000),
                        TextInput::make('icon')
                            ->maxLength(100)
                            ->placeholder('e.g. heroicon-o-academic-cap')
                            ->helperText('Optional Heroicon name for display on the frontend.'),
                        TextInput::make('display_order')
                            ->numeric()
                            ->default(0)
                            ->minValue(0),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ]),
            ]);
    }
}
