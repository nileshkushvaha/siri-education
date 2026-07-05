<?php

namespace App\Filament\Resources\Academic\Schemas;

use App\Actions\GeneratePageSlugAction;
use App\Enums\AcademicStatus;
use App\Models\AcademicLevel;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AcademicLevelForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Level Information')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(100)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, $set): void {
                                if (blank($state)) {
                                    return;
                                }

                                $set('slug', app(GeneratePageSlugAction::class)->execute($state, null, AcademicLevel::class));
                            }),
                        TextInput::make('slug')
                            ->required()
                            ->unique('academic_levels', 'slug', ignoreRecord: true)
                            ->maxLength(100)
                            ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/'),
                        Textarea::make('description')
                            ->rows(3)
                            ->maxLength(1000),
                        TextInput::make('min_grade')
                            ->label('Min Grade')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(12)
                            ->helperText('Leave blank for levels not bound to a grade range (e.g. Undergraduate).'),
                        TextInput::make('max_grade')
                            ->label('Max Grade')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(12),
                        Select::make('status')
                            ->options(
                                collect(AcademicStatus::cases())
                                    ->mapWithKeys(fn (AcademicStatus $s) => [$s->value => $s->label()])
                                    ->toArray()
                            )
                            ->default(AcademicStatus::Active->value)
                            ->required(),
                        TextInput::make('display_order')
                            ->numeric()
                            ->default(0)
                            ->minValue(0),
                    ]),
            ]);
    }
}
