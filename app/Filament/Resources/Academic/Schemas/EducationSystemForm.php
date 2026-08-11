<?php

declare(strict_types=1);

namespace App\Filament\Resources\Academic\Schemas;

use App\Enums\AcademicStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class EducationSystemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Education System')
                ->columnSpanFull()
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(150)
                            ->placeholder('e.g. CBSE, IB, GCSE')
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, $set, string $operation): void {
                                if ($operation === 'create' && filled($state)) {
                                    $set('slug', Str::slug($state));
                                }
                            }),
                        TextInput::make('slug')
                            ->required()
                            ->maxLength(150)
                            ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                            ->placeholder('e.g. cbse')
                            ->helperText('Unique identifier for this education system.'),
                    ]),
                    Grid::make(2)->schema([
                        TextInput::make('code')
                            ->maxLength(30)
                            ->placeholder('e.g. CBSE')
                            ->helperText('Optional short code shown in compact UI.'),
                        Select::make('status')
                            ->options(
                                collect(AcademicStatus::cases())
                                    ->mapWithKeys(fn (AcademicStatus $s) => [$s->value => $s->label()])
                                    ->toArray()
                            )
                            ->default(AcademicStatus::Active->value)
                            ->required()
                            ->placeholder('Select status'),
                    ]),
                    Textarea::make('description')
                        ->rows(3)
                        ->maxLength(2000)
                        ->columnSpanFull()
                        ->placeholder('Brief overview of this education system…'),
                    TextInput::make('display_order')
                        ->numeric()
                        ->default(0)
                        ->minValue(0)
                        ->placeholder('0'),
                ]),
        ]);
    }
}
