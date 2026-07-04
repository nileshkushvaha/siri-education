<?php

namespace App\Forms\Blocks;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;

class PricingBlockForm
{
    public static function schema(): array
    {
        return [
            Section::make('Pricing Section')
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

            Section::make('Plans')
                ->description('Add pricing plans and the features included in each plan.')
                ->collapsible(false)
                ->schema([
                    Repeater::make('plans')
                        ->label('Plans')
                        ->schema([
                            TextInput::make('name')
                                ->label('Plan Name')
                                ->required()
                                ->maxLength(255)
                                ->columnSpan(6),

                            TextInput::make('badge')
                                ->label('Badge')
                                ->maxLength(120)
                                ->columnSpan(6),

                            Textarea::make('description')
                                ->label('Description')
                                ->rows(2)
                                ->maxLength(500)
                                ->columnSpanFull(),

                            TextInput::make('price')
                                ->label('Price')
                                ->maxLength(120)
                                ->columnSpan(6),

                            TextInput::make('period')
                                ->label('Billing Period')
                                ->maxLength(120)
                                ->columnSpan(6),

                            Textarea::make('features')
                                ->label('Features')
                                ->helperText('Enter one feature per line.')
                                ->rows(5)
                                ->columnSpanFull(),

                            TextInput::make('button_text')
                                ->label('Button Text')
                                ->maxLength(120)
                                ->columnSpan(6),

                            TextInput::make('button_link')
                                ->label('Button URL')
                                ->maxLength(500)
                                ->columnSpan(6),

                            Toggle::make('highlighted')
                                ->label('Highlight Plan')
                                ->default(false)
                                ->columnSpanFull(),
                        ])
                        ->columns(12)
                        ->minItems(1)
                        ->defaultItems(3)
                        ->addAction(fn ($action) => $action->label('Add Plan'))
                        ->deleteAction(fn ($action) => $action->requiresConfirmation())
                        ->columnSpanFull(),
                ]),
        ];
    }
}
