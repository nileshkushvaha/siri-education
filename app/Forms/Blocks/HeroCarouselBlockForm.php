<?php

namespace App\Forms\Blocks;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;

class HeroCarouselBlockForm
{
    public static function schema(): array
    {
        return [
            Section::make('Headline Frame')
                ->description('The wrapping sentence stays still while each slide rotates the middle line through it')
                ->collapsible(false)
                ->schema([
                    TextInput::make('prefix_text')
                        ->label('Opening Line')
                        ->maxLength(120)
                        ->placeholder('e.g., Learn faster with'),

                    TextInput::make('suffix_text')
                        ->label('Closing Line')
                        ->maxLength(120)
                        ->placeholder('e.g., verified expert tutors'),

                    TextInput::make('footnote')
                        ->label('Footnote')
                        ->maxLength(120)
                        ->placeholder('e.g., *T&C apply'),
                ]),

            Section::make('Rotation Settings')
                ->collapsible(false)
                ->schema([
                    Toggle::make('autoplay')
                        ->label('Rotate Automatically')
                        ->default(true),

                    Select::make('interval')
                        ->label('Seconds Per Slide')
                        ->options([
                            4000 => '4 seconds',
                            5000 => '5 seconds',
                            6000 => '6 seconds',
                            8000 => '8 seconds',
                        ])
                        ->default(5000),

                    Toggle::make('show_arrows')
                        ->label('Show Previous/Next Arrows')
                        ->default(true),
                ]),

            Section::make('Slides')
                ->description('Each slide supplies one rotating line, one background image, and its own buttons')
                ->collapsible(false)
                ->schema([
                    Repeater::make('slides')
                        ->label('Slides')
                        ->schema([
                            TextInput::make('tab_label')
                                ->label('Tab Label')
                                ->helperText('Shown as the pill under the headline')
                                ->required()
                                ->maxLength(60)
                                ->columnSpan(6),

                            TextInput::make('rotating_text')
                                ->label('Rotating Line')
                                ->helperText('The phrase that cycles inside the headline')
                                ->required()
                                ->maxLength(120)
                                ->columnSpan(6),

                            FileUpload::make('image')
                                ->label('Subject Photo')
                                ->helperText('Transparent-background PNG cutout, waist-up, facing camera. Anchored bottom-right and allowed to bleed off the frame.')
                                ->image()
                                // Pinned explicitly: no config/filament.php is published, so
                                // the upload would otherwise land on the default `local` disk
                                // under storage/app/private and never be web-servable.
                                ->disk('public')
                                ->directory('blocks/hero-carousel')
                                ->maxSize(5120)
                                ->columnSpanFull(),

                            TextInput::make('badge_title')
                                ->label('Badge Title')
                                ->helperText('Card above the subject, e.g. "Exam Preparation"')
                                ->maxLength(60)
                                ->columnSpan(6),

                            TextInput::make('badge_subtitle')
                                ->label('Badge Subtitle')
                                ->maxLength(60)
                                ->columnSpan(6),

                            TagsInput::make('highlights')
                                ->label('Highlight Pills')
                                ->helperText('Up to three float beside the subject, e.g. "Doubts Cleared"')
                                ->columnSpanFull(),

                            TextInput::make('primary_button_text')
                                ->label('Primary Button Text')
                                ->maxLength(60)
                                ->columnSpan(6),

                            TextInput::make('primary_button_link')
                                ->label('Primary Button Link')
                                ->maxLength(255)
                                ->columnSpan(6),

                            TextInput::make('secondary_button_text')
                                ->label('Secondary Button Text')
                                ->maxLength(60)
                                ->columnSpan(6),

                            TextInput::make('secondary_button_link')
                                ->label('Secondary Button Link')
                                ->maxLength(255)
                                ->columnSpan(6),
                        ])
                        ->columns(12)
                        ->minItems(1)
                        ->defaultItems(1)
                        ->addAction(fn ($action) => $action->label('Add Slide'))
                        ->deleteAction(fn ($action) => $action->requiresConfirmation())
                        ->columnSpanFull(),
                ]),
        ];
    }
}
