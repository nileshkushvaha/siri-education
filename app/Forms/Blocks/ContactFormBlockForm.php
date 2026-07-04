<?php

namespace App\Forms\Blocks;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;

class ContactFormBlockForm
{
    public static function schema(): array
    {
        return [
            Section::make('Contact Form Header')
                ->collapsible(false)
                ->schema([
                    TextInput::make('title')
                        ->label('Form Title')
                        ->maxLength(255),

                    Textarea::make('description')
                        ->label('Form Description')
                        ->rows(2)
                        ->maxLength(500),
                ]),

            Section::make('Form Fields')
                ->description('Define contact form fields')
                ->collapsible(false)
                ->schema([
                    Repeater::make('fields')
                        ->label('Fields')
                        ->schema([
                            TextInput::make('label')
                                ->label('Field Label')
                                ->required()
                                ->maxLength(255)
                                ->columnSpan(6),

                            Select::make('type')
                                ->label('Field Type')
                                ->required()
                                ->options([
                                    'text' => 'Text',
                                    'email' => 'Email',
                                    'phone' => 'Phone',
                                    'textarea' => 'Textarea',
                                    'select' => 'Select',
                                ])
                                ->default('text')
                                ->columnSpan(6),

                            TextInput::make('placeholder')
                                ->label('Placeholder')
                                ->maxLength(255)
                                ->columnSpanFull(),

                            Toggle::make('required')
                                ->label('Required Field')
                                ->default(true)
                                ->columnSpan(6),

                            TextInput::make('options')
                                ->label('Options (comma-separated, for select only)')
                                ->maxLength(500)
                                ->columnSpan(6),
                        ])
                        ->columns(12)
                        ->minItems(1)
                        ->defaultItems(3)
                        ->addAction(fn ($action) => $action->label('Add Field'))
                        ->deleteAction(fn ($action) => $action->requiresConfirmation())
                        ->columnSpanFull(),
                ]),

            Section::make('Form Messages')
                ->collapsible(false)
                ->schema([
                    TextInput::make('button_text')
                        ->label('Submit Button Text')
                        ->maxLength(100),

                    Textarea::make('success_message')
                        ->label('Success Message')
                        ->rows(2)
                        ->maxLength(300),
                ]),
        ];
    }
}
