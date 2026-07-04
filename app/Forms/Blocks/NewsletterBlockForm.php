<?php

namespace App\Forms\Blocks;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;

class NewsletterBlockForm
{
    public static function schema(): array
    {
        return [
            Section::make('Newsletter Content')
                ->collapsible(false)
                ->schema([
                    TextInput::make('eyebrow')->label('Eyebrow')->maxLength(120),
                    TextInput::make('title')->label('Title')->maxLength(255),
                    Textarea::make('description')->label('Description')->rows(3)->maxLength(700),
                ]),

            Section::make('Newsletter Form')
                ->collapsible(false)
                ->schema([
                    TextInput::make('name_label')->label('Name Label')->maxLength(120)->columnSpan(6),
                    TextInput::make('name_placeholder')->label('Name Placeholder')->maxLength(120)->columnSpan(6),
                    TextInput::make('email_label')->label('Email Label')->maxLength(120)->columnSpan(6),
                    TextInput::make('email_placeholder')->label('Email Placeholder')->maxLength(120)->columnSpan(6),
                    TextInput::make('button_text')->label('Button Text')->maxLength(120),
                    Textarea::make('consent_text')->label('Consent Text')->rows(2)->maxLength(300),
                    Textarea::make('success_message')->label('Success Message')->rows(2)->maxLength(300),
                ])
                ->columns(12),
        ];
    }
}
