<?php

namespace App\Filament\Resources\Faq\Schemas;

use App\Enums\FaqAudience;
use App\Enums\FaqStatus;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;

class FaqForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('faq_tabs')
                    ->tabs([
                        Tabs\Tab::make('Content')
                            ->schema([
                                Section::make('FAQ Details')
                                    ->schema([
                                        Select::make('faq_category_id')
                                            ->label('Category')
                                            ->relationship('category', 'name')
                                            ->searchable()
                                            ->preload()
                                            ->required(),
                                        TextInput::make('question')
                                            ->required()
                                            ->maxLength(500),
                                        RichEditor::make('answer')
                                            ->required()
                                            ->toolbarButtons([
                                                'bold',
                                                'italic',
                                                'underline',
                                                'strike',
                                                'link',
                                                'orderedList',
                                                'bulletList',
                                                'blockquote',
                                                'codeBlock',
                                                'undo',
                                                'redo',
                                            ]),
                                    ]),
                            ]),

                        Tabs\Tab::make('Settings')
                            ->schema([
                                Section::make('Audience & Visibility')
                                    ->schema([
                                        Select::make('audience')
                                            ->label('Audience')
                                            ->options(
                                                collect(FaqAudience::cases())
                                                    ->mapWithKeys(fn (FaqAudience $a) => [$a->value => $a->label()])
                                                    ->toArray()
                                            )
                                            ->multiple()
                                            ->required()
                                            ->helperText('Select which user groups can see this FAQ.'),
                                        Select::make('status')
                                            ->options(
                                                collect(FaqStatus::cases())
                                                    ->mapWithKeys(fn (FaqStatus $s) => [$s->value => $s->label()])
                                                    ->toArray()
                                            )
                                            ->default(FaqStatus::Draft->value)
                                            ->required(),
                                        DateTimePicker::make('published_at')
                                            ->label('Published At')
                                            ->helperText('Auto-set when first published. Override to schedule.'),
                                        TextInput::make('display_order')
                                            ->numeric()
                                            ->default(0)
                                            ->minValue(0),
                                        Toggle::make('featured')
                                            ->label('Featured')
                                            ->helperText('Show in the Featured section on the help center.'),
                                    ]),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
