<?php

namespace App\Filament\Resources\Academic\Schemas;

use App\Actions\GeneratePageSlugAction;
use App\Models\SkillLevel;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SkillLevelForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Skill Level Information')
                    ->columnSpanFull()
                    ->schema([
                        // Slugs are technical identifiers: generate once during
                        // creation, keep them hidden, and preserve them on rename.
                        Grid::make(2)->schema([
                            TextInput::make('name')
                                ->required()
                                ->maxLength(100)
                                ->placeholder('e.g. Beginner')
                                ->live(onBlur: true)
                                ->afterStateUpdated(function ($state, $set, string $operation): void {
                                    if ($operation !== 'create' || blank($state)) {
                                        return;
                                    }

                                    $set('slug', app(GeneratePageSlugAction::class)->execute($state, null, SkillLevel::class));
                                }),
                            TextInput::make('slug')
                                ->required()
                                ->unique('skill_levels', 'slug', ignoreRecord: true)
                                ->maxLength(100)
                                ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                                ->placeholder('e.g. beginner')
                                ->helperText('Changing this changes the public URL.')
                                ->hidden()
                                ->disabledOn('edit')
                                ->dehydrated(fn (string $operation): bool => $operation === 'create')
                                ->dehydratedWhenHidden()
                                ->dehydrateStateUsing(fn (?string $state, $get): string => filled($state)
                                    ? $state
                                    : app(GeneratePageSlugAction::class)->execute((string) $get('name'), null, SkillLevel::class)),
                        ]),

                        // Display settings.
                        Grid::make(2)->schema([
                            Toggle::make('is_active')
                                ->label('Active')
                                ->default(true),
                        ]),
                    ]),
            ]);
    }
}
