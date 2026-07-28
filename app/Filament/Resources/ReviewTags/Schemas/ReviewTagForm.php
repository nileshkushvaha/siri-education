<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReviewTags\Schemas;

use App\Reviews\Enums\LessonReviewEligibilityMode;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ReviewTagForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Tag')
                    ->description('Students choose from these — never free text. The key is permanent once reviews start referencing it.')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('key')
                                ->required()
                                ->maxLength(64)
                                ->unique(ignoreRecord: true)
                                ->alphaDash()
                                ->disabledOn('edit')
                                ->dehydratedWhenHidden()
                                ->helperText('Use lowercase words separated by underscores (e.g. great_explanations). Permanent — already-submitted reviews store this key.'),
                            TextInput::make('label')
                                ->required()
                                ->maxLength(100)
                                ->placeholder('e.g. Great Explanations'),
                        ]),
                        CheckboxList::make('applicable_modes')
                            ->label('Applicable to')
                            ->options(collect(LessonReviewEligibilityMode::cases())
                                ->filter(fn (LessonReviewEligibilityMode $mode) => $mode !== LessonReviewEligibilityMode::Disabled)
                                ->mapWithKeys(fn (LessonReviewEligibilityMode $mode) => [$mode->value => $mode->label()])
                                ->toArray())
                            ->required()
                            ->columns(2)
                            ->helperText('Which review modes may offer this tag to a submitting student.'),
                        Grid::make(2)->schema([
                            Toggle::make('is_active')
                                ->default(true)
                                ->helperText('Inactive tags stop being offered to new submissions immediately; existing review snapshots are unaffected.'),
                            TextInput::make('sort_order')
                                ->numeric()
                                ->default(0)
                                ->placeholder('0'),
                        ]),
                    ]),
            ]);
    }
}
