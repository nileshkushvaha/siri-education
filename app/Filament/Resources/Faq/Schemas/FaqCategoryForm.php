<?php

namespace App\Filament\Resources\Faq\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FaqCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Category details')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('name')
                                ->required()
                                ->maxLength(255),
                            TextInput::make('icon')
                                ->maxLength(100)
                                ->placeholder('e.g. heroicon-o-question-mark-circle')
                                ->helperText('Optional Heroicon name used when displaying this category.'),
                        ]),
                        Textarea::make('description')
                            ->rows(3)
                            ->maxLength(1000)
                            ->columnSpanFull(),
                    ]),

                Section::make('Visibility and order')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('display_order')
                                ->numeric()
                                ->default(0)
                                ->minValue(0)
                                ->helperText('Lower numbers appear first when categories are listed.'),
                            Toggle::make('is_active')
                                ->label('Active')
                                ->default(true)
                                ->helperText('Inactive categories are hidden from the public FAQ page.'),
                        ]),
                    ]),
            ]);
    }
}
