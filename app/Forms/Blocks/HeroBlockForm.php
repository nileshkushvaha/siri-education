<?php

namespace App\Forms\Blocks;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;

class HeroBlockForm
{
    public static function schema(): array
    {
        return [
            Section::make('Hero Content')
                ->description('Create a compelling hero section with image and call-to-action')
                ->collapsible(false)
                ->schema([
                    TextInput::make('title')
                        ->label('Hero Title')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('Enter headline'),

                    Textarea::make('subtitle')
                        ->label('Subtitle')
                        ->maxLength(500)
                        ->rows(3)
                        ->placeholder('Enter supporting text'),

                    FileUpload::make('image')
                        ->label('Background Image')
                        ->image()

                        ->directory('blocks/hero')
                        ->maxSize(5120),

                    TextInput::make('image_alt')
                        ->label('Image alt text')
                        ->maxLength(255)
                        ->helperText('Describes the image for screen readers and search engines. Falls back to the hero title when blank.')
                        ->placeholder('e.g. Student studying at a laptop'),

                    Select::make('heading_level')
                        ->label('Heading level')
                        ->options([
                            'h1' => 'H1 — this hero is the page heading',
                            'h2' => 'H2 — the layout already renders an H1 above',
                        ])
                        ->default('h1')
                        ->helperText('Templates that render their own page title need H2 here so the page keeps one H1.'),

                    TextInput::make('button_text')
                        ->label('Button Text')
                        ->placeholder('e.g., Learn More'),

                    TextInput::make('button_link')
                        ->label('Button Link')
                        ->url()
                        ->placeholder('e.g., /about'),

                    Select::make('button_style')
                        ->label('Button Style')
                        ->options([
                            'primary' => 'Primary',
                            'secondary' => 'Secondary',
                            'outline' => 'Outline',
                            'ghost' => 'Ghost',
                        ])
                        ->default('primary'),
                ]),
        ];
    }
}
