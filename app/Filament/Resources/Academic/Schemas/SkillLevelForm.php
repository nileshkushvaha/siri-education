<?php

namespace App\Filament\Resources\Academic\Schemas;

use App\Actions\GeneratePageSlugAction;
use App\Models\SkillLevel;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SkillLevelForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Skill Level Information')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(100)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, $set): void {
                                if (blank($state)) {
                                    return;
                                }

                                $set('slug', app(GeneratePageSlugAction::class)->execute($state, null, SkillLevel::class));
                            }),
                        TextInput::make('slug')
                            ->required()
                            ->unique('skill_levels', 'slug', ignoreRecord: true)
                            ->maxLength(100)
                            ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/'),
                        TextInput::make('display_order')
                            ->numeric()
                            ->default(0)
                            ->minValue(0),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ]),
            ]);
    }
}
