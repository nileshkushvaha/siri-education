<?php

namespace App\Forms\Blocks;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;

class FeaturesBlockForm
{
    public static function schema(): array
    {
        return [
            Section::make('Feature Section')
                ->collapsible(false)
                ->schema([
                    TextInput::make('eyebrow')
                        ->label('Eyebrow')
                        ->maxLength(120),

                    TextInput::make('title')
                        ->label('Title')
                        ->maxLength(255),

                    Textarea::make('description')
                        ->label('Description')
                        ->rows(3)
                        ->maxLength(700),

                    Select::make('columns')
                        ->label('Grid Columns')
                        ->options([
                            2 => '2 Columns',
                            3 => '3 Columns',
                            4 => '4 Columns',
                        ])
                        ->default(3),
                ]),

            Section::make('Features')
                ->description('Add reusable feature cards for the page.')
                ->collapsible(false)
                ->schema([
                    Repeater::make('features')
                        ->label('Features')
                        ->schema([
                            TextInput::make('icon')
                                ->label('Icon Label or Class')
                                ->maxLength(120)
                                ->columnSpan(4),

                            TextInput::make('title')
                                ->label('Title')
                                ->required()
                                ->maxLength(255)
                                ->columnSpan(8),

                            Textarea::make('description')
                                ->label('Description')
                                ->rows(3)
                                ->maxLength(700)
                                ->columnSpanFull(),

                            TextInput::make('link_label')
                                ->label('Link Label')
                                ->maxLength(120)
                                ->columnSpan(6),

                            TextInput::make('link')
                                ->label('Link URL')
                                ->maxLength(500)
                                ->columnSpan(6),
                        ])
                        ->columns(12)
                        ->minItems(1)
                        ->defaultItems(3)
                        ->addAction(fn ($action) => $action->label('Add Feature'))
                        ->deleteAction(fn ($action) => $action->requiresConfirmation())
                        ->columnSpanFull(),
                ]),
        ];
    }
}
