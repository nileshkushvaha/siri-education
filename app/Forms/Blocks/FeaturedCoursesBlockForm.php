<?php

namespace App\Forms\Blocks;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;

class FeaturedCoursesBlockForm
{
    public static function schema(): array
    {
        return [
            Section::make('Featured Courses Header')
                ->collapsible(false)
                ->schema([
                    TextInput::make('eyebrow')->label('Eyebrow')->maxLength(120),
                    TextInput::make('title')->label('Title')->maxLength(255),
                    Textarea::make('description')->label('Description')->rows(3)->maxLength(700),
                    Select::make('columns')
                        ->label('Grid Columns')
                        ->options([2 => '2 Columns', 3 => '3 Columns', 4 => '4 Columns'])
                        ->default(3),
                    TextInput::make('link_label')->label('Section Link Label')->maxLength(120)->columnSpan(6),
                    TextInput::make('link_url')->label('Section Link URL')->maxLength(500)->columnSpan(6),
                ])
                ->columns(12),

            Section::make('Courses')
                ->description('Author featured course cards directly in the CMS until the Course module ships.')
                ->collapsible(false)
                ->schema([
                    Repeater::make('courses')
                        ->label('Courses')
                        ->schema([
                            TextInput::make('title')->label('Title')->required()->maxLength(255)->columnSpan(8),
                            TextInput::make('badge')->label('Badge')->maxLength(120)->columnSpan(4),
                            TextInput::make('category')->label('Category')->maxLength(120)->columnSpan(6),
                            TextInput::make('instructor')->label('Instructor')->maxLength(255)->columnSpan(6),
                            Textarea::make('description')->label('Description')->rows(3)->maxLength(700)->columnSpanFull(),
                            TextInput::make('image')->label('Image URL')->maxLength(500)->columnSpanFull(),
                            TextInput::make('url')->label('Course URL')->maxLength(500)->columnSpanFull(),
                            TextInput::make('price')->label('Price')->maxLength(120)->columnSpan(4),
                            TextInput::make('duration')->label('Duration')->maxLength(120)->columnSpan(4),
                            TextInput::make('level')->label('Level')->maxLength(120)->columnSpan(4),
                        ])
                        ->columns(12)
                        ->minItems(1)
                        ->defaultItems(3)
                        ->addAction(fn ($action) => $action->label('Add Course'))
                        ->deleteAction(fn ($action) => $action->requiresConfirmation())
                        ->columnSpanFull(),
                ]),
        ];
    }
}
